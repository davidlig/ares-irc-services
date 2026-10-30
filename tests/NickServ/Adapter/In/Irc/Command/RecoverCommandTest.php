<?php

declare(strict_types=1);

namespace App\Tests\NickServ\Adapter\In\Irc\Command;

use App\Bootstrap\Adapter\Out\Translation\IrcYamlTranslationLoader;
use App\Irc\Application\Port\In\SenderView;
use App\Irc\Application\Port\In\ServiceNicknameProviderInterface;
use App\Irc\Application\Port\In\ServiceNicknameRegistry;
use App\NickServ\Adapter\In\Irc\Command\RecoverCommand;
use App\NickServ\Adapter\In\Irc\NickServCommandRegistry;
use App\NickServ\Adapter\In\Irc\NickServContext;
use App\NickServ\Adapter\In\Irc\NickServNotifierInterface;
use App\NickServ\Adapter\Out\InMemory\PendingVerificationRegistry;
use App\NickServ\Adapter\Out\InMemory\RecoveryTokenRegistry;
use App\NickServ\Application\Model\NicknameAuthenticationMode;
use App\NickServ\Application\Port\Out\NicknameAuthenticationModeQuery;
use App\NickServ\Application\UseCase\Recover\RecoverNick;
use App\NickServ\Application\UseCase\Recover\RecoverNickHandlerInterface;
use App\NickServ\Application\UseCase\Recover\RecoverNickResult;
use App\Shared\Adapter\In\Irc\Presentation\FixedStyleProvider;
use App\Shared\Adapter\In\Irc\Presentation\IrcRenderer;
use App\Shared\Adapter\In\Irc\Presentation\PresentationStyle;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Stringable;
use Symfony\Component\Translation\Translator;
use Symfony\Contracts\Translation\TranslatorInterface;

use function array_key_last;
use function base64_encode;
use function dirname;
use function implode;
use function inet_pton;
use function is_scalar;
use function ksort;
use function substr_count;

#[CoversClass(RecoverCommand::class)]
final class RecoverCommandTest extends TestCase
{
    #[Test]
    public function exposesRecoverMetadata(): void
    {
        $command = new RecoverCommand(
            $this->createStub(RecoverNickHandlerInterface::class),
            $this->authenticationMode(),
        );

        self::assertSame('RECOVER', $command->getName());
        self::assertSame([], $command->getAliases());
        self::assertSame(1, $command->getMinArgs());
        self::assertSame('recover.syntax', $command->getSyntaxKey());
        self::assertSame('recover.help', $command->getHelpKey());
        self::assertSame('recover.example', $command->getExampleKey());
        self::assertSame(7, $command->getOrder());
        self::assertSame('recover.short', $command->getShortDescKey());
        self::assertSame([], $command->getSubCommandHelp());
        self::assertFalse($command->isOperOnly());
        self::assertNull($command->getRequiredPermission());
        self::assertSame([], $command->getHelpParams());
    }

    #[Test]
    public function doesNothingWhenSenderIsNull(): void
    {
        $handler = $this->createMock(RecoverNickHandlerInterface::class);
        $handler->expects(self::never())->method('handle');

        $command = new RecoverCommand($handler, $this->authenticationMode());
        $messages = [];
        $command->execute($this->createContext(null, $messages, ['Target']));

        self::assertSame([], $messages);
    }

    #[Test]
    public function mapsRequestTokenArgsToRecoverNick(): void
    {
        $sender = new SenderView('UID1', 'SenderNick', 'ident', 'host.net', 'cloak', base64_encode((string) inet_pton('192.168.1.1')));
        $handler = $this->createMock(RecoverNickHandlerInterface::class);
        $handler->expects(self::once())->method('handle')->with(self::callback(
            static fn (RecoverNick $dto): bool => 'TargetNick' === $dto->nickname
                && null === $dto->token
                && 'SenderNick' === $dto->senderNick
                && '192.168.1.1' === $dto->senderIp
                && 'ident@host.net' === $dto->senderHost
                && 'ip:wKgBAQ==' === $dto->clientKey,
        ))->willReturn(RecoverNickResult::instructions('TargetNick'));

        $command = new RecoverCommand($handler, $this->authenticationMode());
        $messages = [];
        $command->execute($this->createContext($sender, $messages, ['TargetNick']));

        self::assertSame(['recover.instructions [%nickname%: TargetNick]'], $messages);
    }

    #[Test]
    public function mapsConsumeTokenArgsToRecoverNick(): void
    {
        $sender = new SenderView('UID1', 'SenderNick', 'ident', 'host.net', 'cloak', '*');
        $handler = $this->createMock(RecoverNickHandlerInterface::class);
        $handler->expects(self::once())->method('handle')->with(self::callback(
            static fn (RecoverNick $dto): bool => 'TargetNick' === $dto->nickname
                && 'my-token' === $dto->token
                && '*' === $dto->senderIp,
        ))->willReturn(RecoverNickResult::passwordReset('TargetNick', 'new-secret-12', 'replacement-token'));

        $command = new RecoverCommand($handler, $this->authenticationMode());
        $messages = [];
        $command->execute($this->createContext($sender, $messages, ['TargetNick', 'my-token']));

        self::assertSame([
            'recover.success_identify [%identify_cmd%: /msg NickServ IDENTIFY TargetNick new-secret-12]',
            'recover.success_then_change',
            'rescue.optional_replacement [%token%: replacement-token]',
        ], $messages);
    }

    #[Test]
    public function presentsNativeNicknameAuthenticationAfterPasswordReset(): void
    {
        $sender = new SenderView('UID1', 'SenderNick', 'ident', 'host.net', 'cloak', '*');
        $handler = $this->createStub(RecoverNickHandlerInterface::class);
        $handler->method('handle')->willReturn(RecoverNickResult::passwordReset('TargetNick', 'new-secret-12', 'replacement-token'));

        $command = new RecoverCommand(
            $handler,
            $this->authenticationMode(NicknameAuthenticationMode::NativeNick),
        );
        $messages = [];
        $command->execute($this->createContext($sender, $messages, ['TargetNick', 'my-token']));

        self::assertSame([
            'recover.success_identify [%identify_cmd%: /NICK TargetNick:new-secret-12 | /NICK TargetNick!new-secret-12]',
            'recover.success_then_change',
            'rescue.optional_replacement [%token%: replacement-token]',
        ], $messages);
    }

    #[Test]
    public function recoverySecretInstructionsPreserveLiteralTokensAndBotName(): void
    {
        $handler = $this->createStub(RecoverNickHandlerInterface::class);
        $handler->method('handle')->willReturn(RecoverNickResult::passwordReset('Alice', 'temp%bot%secret', 'rescue%bot%token'));
        $translator = new Translator('es');
        $translator->addLoader('yaml', new IrcYamlTranslationLoader());
        $translator->addResource('yaml', dirname(__DIR__, 6) . '/translations/nickserv.es.yaml', 'es', 'nickserv');
        $messages = [];
        $notifier = $this->createStub(NickServNotifierInterface::class);
        $notifier->method('getNick')->willReturn('CustomNick');
        $notifier->method('sendMessage')->willReturnCallback(static function (string $target, string $message) use (&$messages): void { $messages[] = $message; });
        $renderer = new IrcRenderer(new FixedStyleProvider(PresentationStyle::default()));
        $context = new NickServContext(new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip'), null, 'RECOVER', ['Alice', 'token'], $notifier, $translator, 'es', 'UTC', 'NOTICE', new NickServCommandRegistry([]), new PendingVerificationRegistry(), new RecoveryTokenRegistry(), $this->createServiceNicks(), $renderer);
        new RecoverCommand($handler, $this->authenticationMode())->execute($context);
        self::assertStringContainsString('temp%bot%secret', $messages[0]);
        self::assertStringContainsString('/msg CustomNick ', $messages[1]);
        self::assertSame(2, substr_count($messages[2], 'rescue%bot%token'));
        self::assertStringNotContainsString('%token_display%', $messages[2]);
    }

    #[Test]
    public function recoveryInstructionsRenderLocalizedCommandFragments(): void
    {
        $handler = $this->createStub(RecoverNickHandlerInterface::class);
        $handler->method('handle')->willReturn(RecoverNickResult::instructions('Alice'));
        $translator = new Translator('es');
        $translator->addLoader('yaml', new IrcYamlTranslationLoader());
        $translator->addResource('yaml', dirname(__DIR__, 6) . '/translations/nickserv.es.yaml', 'es', 'nickserv');
        $messages = [];
        $notifier = $this->createStub(NickServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $target, string $message) use (&$messages): void { $messages[] = $message; });
        $renderer = new IrcRenderer(new FixedStyleProvider(PresentationStyle::default()));
        $context = new NickServContext(new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip'), null, 'RECOVER', ['Alice'], $notifier, $translator, 'es', 'UTC', 'NOTICE', new NickServCommandRegistry([]), new PendingVerificationRegistry(), new RecoveryTokenRegistry(), $this->createServiceNicks(), $renderer);
        new RecoverCommand($handler, $this->authenticationMode())->execute($context);
        self::assertStringStartsWith("\x0310ℹ\x03\x0F ", $messages[0]);
        self::assertStringContainsString("\x02\x0303RECOVER Alice <token>\x03\x0F", $messages[0]);
        self::assertStringContainsString("\x02\x0303VERIFY\x03\x0F", $messages[0]);
    }

    /**
     * @param string[] $expectedMessages
     */
    #[Test]
    #[DataProvider('outcomesDataProvider')]
    public function presentsAllOutcomes(RecoverNickResult $result, array $expectedMessages): void
    {
        $sender = new SenderView('UID1', 'SenderNick', 'ident', 'host.net', 'cloak', '');
        $handler = $this->createMock(RecoverNickHandlerInterface::class);
        $handler->expects(self::once())->method('handle')->willReturn($result);

        $command = new RecoverCommand($handler, $this->authenticationMode());
        $messages = [];
        $command->execute($this->createContext($sender, $messages, ['TargetNick']));

        self::assertSame($expectedMessages, $messages);
    }

    /**
     * @return array<string, array{0: RecoverNickResult, 1: string[]}>
     */
    public static function outcomesDataProvider(): array
    {
        return [
            'not_registered' => [
                RecoverNickResult::notRegistered('TargetNick'),
                ['error.nick_not_registered [%nickname%: TargetNick]'],
            ],
            'pending' => [
                RecoverNickResult::pending('TargetNick'),
                ['recover.pending [%nickname%: TargetNick]'],
            ],
            'suspended' => [
                RecoverNickResult::suspended('TargetNick', 'rule violation'),
                ['recover.suspended [%nickname%: TargetNick, %reason%: rule violation]'],
            ],
            'forbidden' => [
                RecoverNickResult::forbidden('TargetNick'),
                ['recover.forbidden [%nickname%: TargetNick]'],
            ],
            'unavailable' => [
                RecoverNickResult::credentialUnavailable('TargetNick'),
                ['rescue.unavailable'],
            ],
            'pending_deletion' => [RecoverNickResult::pendingDeletion('TargetNick'), ['recover.pending_deletion [%nickname%: TargetNick]']],
            'throttled' => [
                RecoverNickResult::throttled(125),
                ['recover.throttled [%minutes%: 3]'],
            ],

            'invalid_token' => [
                RecoverNickResult::invalidToken('TargetNick'),
                ['recover.invalid_token [%nickname%: TargetNick]'],
            ],
        ];
    }

    #[Test]
    public function recoveryMessagesDistinguishPromotedTokenAndUnavailableOptionalChallenge(): void
    {
        foreach ([
            [RecoverNickResult::passwordReset('User', 'password', 'emailed', true), 'rescue.replaced [%token%: emailed]'],
            [RecoverNickResult::passwordReset('User', 'password', null, replacementUnavailable: true), 'rescue.replacement_unavailable'],
            [RecoverNickResult::tokenSent('user@example.com'), 'recover.email_sent [%email_hint%: us****@e****.***]'],
            [RecoverNickResult::noEmail('User'), 'recover.no_email [%nickname%: User]'],
            [RecoverNickResult::mailDeliveryFailed(), 'error.mail_failed'],
            [RecoverNickResult::temporarilyUnavailable(), 'rescue.temporarily_unavailable'],
        ] as [$result, $last]) {
            $handler = $this->createStub(RecoverNickHandlerInterface::class);
            $handler->method('handle')->willReturn($result);
            $messages = [];
            new RecoverCommand($handler, $this->authenticationMode())->execute($this->createContext(new SenderView('UID', 'User', 'i', 'h', 'c', '*'), $messages, ['User']));
            self::assertNotEmpty($messages);
            self::assertSame($last, $messages[array_key_last($messages)]);
        }
    }

    #[Test]
    public function handlesInvalidBase64IpGracefully(): void
    {
        $sender = new SenderView('UID1', 'SenderNick', 'ident', 'host.net', 'cloak', '???notbase64???');
        $handler = $this->createMock(RecoverNickHandlerInterface::class);
        $handler->expects(self::once())->method('handle')->with(self::callback(
            static fn (RecoverNick $dto): bool => '???notbase64???' === $dto->senderIp,
        ))->willReturn(RecoverNickResult::instructions('Target'));

        $command = new RecoverCommand($handler, $this->authenticationMode());
        $messages = [];
        $command->execute($this->createContext($sender, $messages, ['Target']));

        self::assertSame(['recover.instructions [%nickname%: Target]'], $messages);
    }

    /**
     * @param string[]     $messages
     * @param list<string> $args
     */
    private function createContext(?SenderView $sender, array &$messages, array $args): NickServContext
    {
        $notifier = $this->createStub(NickServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $target, string $message) use (&$messages): void {
            $messages[] = $message;
        });

        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static function (string $id, array $params = []): string {
            unset($params['%bot%'], $params['%nickserv%']);
            if ([] === $params) {
                return $id;
            }
            ksort($params);
            $formatted = [];
            foreach ($params as $key => $value) {
                $formatted[] = (string) $key . ': ' . (is_scalar($value) || $value instanceof Stringable ? (string) $value : '');
            }

            return $id . ' [' . implode(', ', $formatted) . ']';
        });

        return new NickServContext(
            $sender,
            null,
            'RECOVER',
            $args,
            $notifier,
            $translator,
            'en',
            'UTC',
            'NOTICE',
            new NickServCommandRegistry([]),
            new PendingVerificationRegistry(),
            new RecoveryTokenRegistry(),
            $this->createServiceNicks(),
        );
    }

    private function createServiceNicks(): ServiceNicknameRegistry
    {
        $provider = new class implements ServiceNicknameProviderInterface {
            public function getServiceKey(): string
            {
                return 'nickserv';
            }

            public function getNickname(): string
            {
                return 'NickServ';
            }
        };

        return new ServiceNicknameRegistry([$provider]);
    }

    private function authenticationMode(
        NicknameAuthenticationMode $mode = NicknameAuthenticationMode::ServiceCommand,
    ): NicknameAuthenticationModeQuery {
        $query = $this->createStub(NicknameAuthenticationModeQuery::class);
        $query->method('current')->willReturn($mode);

        return $query;
    }
}
