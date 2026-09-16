<?php

declare(strict_types=1);

namespace App\Tests\NickServ\Adapter\In\Irc\Command;

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
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Stringable;
use Symfony\Contracts\Translation\TranslatorInterface;

use function base64_encode;
use function inet_pton;
use function is_scalar;

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
                && 'ident@host.net' === $dto->senderHost,
        ))->willReturn(RecoverNickResult::tokenSent('user@example.com'));

        $command = new RecoverCommand($handler, $this->authenticationMode());
        $messages = [];
        $command->execute($this->createContext($sender, $messages, ['TargetNick']));

        self::assertSame(['recover.email_sent [%email_hint%: us****@e****.***]'], $messages);
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
        ))->willReturn(RecoverNickResult::passwordReset('TargetNick', 'new-secret-12'));

        $command = new RecoverCommand($handler, $this->authenticationMode());
        $messages = [];
        $command->execute($this->createContext($sender, $messages, ['TargetNick', 'my-token']));

        self::assertSame([
            'recover.success_identify [%identify_cmd%: /msg NickServ IDENTIFY TargetNick new-secret-12]',
            'recover.success_then_change',
        ], $messages);
    }

    #[Test]
    public function presentsNativeNicknameAuthenticationAfterPasswordReset(): void
    {
        $sender = new SenderView('UID1', 'SenderNick', 'ident', 'host.net', 'cloak', '*');
        $handler = $this->createStub(RecoverNickHandlerInterface::class);
        $handler->method('handle')->willReturn(RecoverNickResult::passwordReset('TargetNick', 'new-secret-12'));

        $command = new RecoverCommand(
            $handler,
            $this->authenticationMode(NicknameAuthenticationMode::NativeNick),
        );
        $messages = [];
        $command->execute($this->createContext($sender, $messages, ['TargetNick', 'my-token']));

        self::assertSame([
            'recover.success_identify [%identify_cmd%: /NICK TargetNick:new-secret-12 | /NICK TargetNick!new-secret-12]',
            'recover.success_then_change',
        ], $messages);
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
                ['recover.not_registered [%nickname%: TargetNick]'],
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
            'no_email' => [
                RecoverNickResult::noEmail('TargetNick'),
                ['recover.no_email [%nickname%: TargetNick]'],
            ],
            'throttled' => [
                RecoverNickResult::throttled(125),
                ['recover.throttled [%minutes%: 3]'],
            ],
            'mail_failed' => [
                RecoverNickResult::mailDeliveryFailed(),
                ['error.mail_failed'],
            ],
            'invalid_token' => [
                RecoverNickResult::invalidToken('TargetNick'),
                ['recover.invalid_token [%nickname%: TargetNick]'],
            ],
        ];
    }

    #[Test]
    public function handlesInvalidBase64IpGracefully(): void
    {
        $sender = new SenderView('UID1', 'SenderNick', 'ident', 'host.net', 'cloak', '???notbase64???');
        $handler = $this->createMock(RecoverNickHandlerInterface::class);
        $handler->expects(self::once())->method('handle')->with(self::callback(
            static fn (RecoverNick $dto): bool => '???notbase64???' === $dto->senderIp,
        ))->willReturn(RecoverNickResult::mailDeliveryFailed());

        $command = new RecoverCommand($handler, $this->authenticationMode());
        $messages = [];
        $command->execute($this->createContext($sender, $messages, ['Target']));

        self::assertSame(['error.mail_failed'], $messages);
    }

    /**
     * @param string[] $messages
     * @param string[] $args
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
