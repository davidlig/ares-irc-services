<?php

declare(strict_types=1);

namespace App\Tests\NickServ\Adapter\In\Irc\Command;

use App\Irc\Application\Port\In\SenderView;
use App\Irc\Application\Port\In\ServiceNicknameProviderInterface;
use App\Irc\Application\Port\In\ServiceNicknameRegistry;
use App\NickServ\Adapter\In\Irc\Command\VerifyCommand;
use App\NickServ\Adapter\In\Irc\NickServCommandRegistry;
use App\NickServ\Adapter\In\Irc\NickServContext;
use App\NickServ\Adapter\In\Irc\NickServNotifierInterface;
use App\NickServ\Adapter\Out\InMemory\PendingVerificationRegistry;
use App\NickServ\Adapter\Out\InMemory\RecoveryTokenRegistry;
use App\NickServ\Application\UseCase\Verify\VerifyNick;
use App\NickServ\Application\UseCase\Verify\VerifyNickHandlerInterface;
use App\NickServ\Application\UseCase\Verify\VerifyNickResult;
use App\Shared\Adapter\In\Irc\Presentation\FixedStyleProvider;
use App\Shared\Adapter\In\Irc\Presentation\IrcRenderer;
use App\Shared\Adapter\In\Irc\Presentation\PresentationStyle;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Translation\Loader\ArrayLoader;
use Symfony\Component\Translation\Translator;
use Symfony\Contracts\Translation\TranslatorInterface;

use function base64_encode;
use function implode;
use function inet_pton;

#[CoversClass(VerifyCommand::class)]
final class VerifyCommandTest extends TestCase
{
    #[Test]
    public function exposesVerifyMetadata(): void
    {
        $command = new VerifyCommand($this->createStub(VerifyNickHandlerInterface::class), verificationTokenTtlSeconds: 3600);

        self::assertSame('VERIFY', $command->getName());
        self::assertSame([], $command->getAliases());
        self::assertSame(1, $command->getMinArgs());
        self::assertSame('verify.syntax', $command->getSyntaxKey());
        self::assertSame('verify.help', $command->getHelpKey());
        self::assertSame('verify.example', $command->getExampleKey());
        self::assertSame(3, $command->getOrder());
        self::assertSame('verify.short', $command->getShortDescKey());
        self::assertSame([], $command->getSubCommandHelp());
        self::assertFalse($command->isOperOnly());
        self::assertNull($command->getRequiredPermission());
        self::assertSame(['duration_seconds' => 3600], $command->getHelpParams());
    }

    #[Test]
    public function exposesConfiguredSecondsForHelp(): void
    {
        $command = new VerifyCommand($this->createStub(VerifyNickHandlerInterface::class), verificationTokenTtlSeconds: 300);

        self::assertSame(['duration_seconds' => 300], $command->getHelpParams());
    }

    #[Test]
    public function ignoresMissingSender(): void
    {
        $handler = $this->createMock(VerifyNickHandlerInterface::class);
        $handler->expects(self::never())->method('handle');

        $command = new VerifyCommand($handler, verificationTokenTtlSeconds: 3600);
        $messages = [];
        $command->execute($this->createContext(null, $messages, ['token123']));

        self::assertSame([], $messages);
    }

    #[Test]
    public function mapsSenderAndTokenToTypedInput(): void
    {
        $sender = new SenderView('UID1', 'Alice', 'ident', 'host', 'cloak', base64_encode((string) inet_pton('192.0.2.1')));

        $handler = $this->createMock(VerifyNickHandlerInterface::class);
        $handler->expects(self::once())
            ->method('handle')
            ->with(self::callback(static fn (VerifyNick $input): bool => 'Alice' === $input->nickname
                && 'tok123' === $input->token
                && 'UID1' === $input->senderUid
                && 'Alice' === $input->actorNickname
                && '192.0.2.1' === $input->sourceIp))
            ->willReturn(VerifyNickResult::invalidToken());

        $command = new VerifyCommand($handler, verificationTokenTtlSeconds: 3600);
        $messages = [];
        $command->execute($this->createContext($sender, $messages, ['tok123']));

        self::assertSame(['verify.invalid_token'], $messages);
    }

    #[Test]
    public function preservesRepliesForUnknownAndMalformedSourceIps(): void
    {
        foreach ([['*', null], ['???notbase64???', '???notbase64???']] as [$source, $expectedIp]) {
            $sender = new SenderView('UID1', 'Alice', 'ident', 'host', 'cloak', $source);
            $handler = $this->createMock(VerifyNickHandlerInterface::class);
            $handler->expects(self::once())->method('handle')->with(self::callback(
                static fn (VerifyNick $input): bool => 'tok123' === $input->token && $expectedIp === $input->sourceIp,
            ))->willReturn(VerifyNickResult::invalidToken());

            $command = new VerifyCommand($handler, verificationTokenTtlSeconds: 3600);
            $messages = [];
            $command->execute($this->createContext($sender, $messages, ['tok123']));

            self::assertSame(['verify.invalid_token'], $messages);
            self::assertStringNotContainsString('tok123', implode(' ', $messages));
        }
    }

    /** @param array<string, mixed> $expectedParams */
    #[Test]
    #[DataProvider('outcomes')]
    public function presentsOutcomes(VerifyNickResult $result, string $expectedKey, array $expectedParams): void
    {
        $sender = new SenderView('UID1', 'Alice', 'ident', 'host', 'cloak', 'ip');
        $handler = $this->createStub(VerifyNickHandlerInterface::class);
        $handler->method('handle')->willReturn($result);

        $notifier = $this->createMock(NickServNotifierInterface::class);
        if (null !== $result->nickname) {
            $notifier->expects(self::once())->method('setUserAccount')->with('UID1', 'Alice');
        } else {
            $notifier->expects(self::never())->method('setUserAccount');
        }

        $command = new VerifyCommand($handler, verificationTokenTtlSeconds: 3600);
        /** @var list<array{0: string, 1: array<string, mixed>}> $calls */
        $calls = [];
        $command->execute($this->createContext($sender, $calls, ['tok123'], captureParams: true, notifier: $notifier));

        self::assertCount(1, $calls);
        $call = $calls[0];
        self::assertIsArray($call);
        self::assertSame($expectedKey, $call[0]);
        $params = $call[1];
        self::assertIsArray($params);
        foreach ($expectedParams as $key => $value) {
            self::assertSame($value, $params[$key] ?? null);
        }
    }

    /** @return iterable<string, array{VerifyNickResult, string, array<string, mixed>}> */
    public static function outcomes(): iterable
    {
        yield 'success' => [VerifyNickResult::success('Alice'), 'verify.success', ['%nickname%' => 'Alice']];
        yield 'migration confirmed' => [VerifyNickResult::credentialConfirmed(), 'rescue.confirmed', []];
        yield 'locked out' => [VerifyNickResult::lockedOut(60), 'rescue.locked_out', ['%seconds%' => '60']];
        yield 'no pending' => [VerifyNickResult::noPending(), 'verify.no_pending', []];
        yield 'invalid token' => [VerifyNickResult::invalidToken(), 'verify.invalid_token', []];
    }

    #[Test]
    public function presentsNativeAuthenticationWithoutSettingTheUserAccount(): void
    {
        $sender = new SenderView('UID1', 'Alice', 'ident', 'host', 'cloak', 'ip');
        $handler = $this->createStub(VerifyNickHandlerInterface::class);
        $handler->method('handle')->willReturn(VerifyNickResult::successNativeAuthenticationRequired('Alice'));
        $notifier = $this->createMock(NickServNotifierInterface::class);
        $notifier->expects(self::never())->method('setUserAccount');

        $command = new VerifyCommand($handler, verificationTokenTtlSeconds: 3600);
        $calls = [];
        $command->execute($this->createContext($sender, $calls, ['tok123'], notifier: $notifier));

        self::assertSame(['verify.success_native', 'identify.native_authentication'], $calls);
    }

    #[Test]
    public function verificationSuccessAndRejectionHaveDifferentPresentationRoles(): void
    {
        $handler = $this->createStub(VerifyNickHandlerInterface::class);
        $handler->method('handle')->willReturnOnConsecutiveCalls(VerifyNickResult::success('Alice'), VerifyNickResult::invalidToken(), VerifyNickResult::credentialConfirmed(), VerifyNickResult::lockedOut(60));
        $translator = new Translator('en');
        $translator->addLoader('array', new ArrayLoader());
        $translator->addResource('array', ['verify.success' => 'Verified %nickname%', 'verify.invalid_token' => 'Invalid token', 'rescue.confirmed' => 'Confirmed', 'rescue.locked_out' => 'Wait %seconds%'], 'en', 'nickserv');
        $messages = [];
        $notifier = $this->createStub(NickServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $target, string $message) use (&$messages): void { $messages[] = $message; });
        $provider = $this->createStub(ServiceNicknameProviderInterface::class);
        $provider->method('getServiceKey')->willReturn('nickserv');
        $provider->method('getNickname')->willReturn('NickServ');
        $renderer = new IrcRenderer(new FixedStyleProvider(PresentationStyle::default()));
        $sender = new SenderView('UID1', 'Alice', 'ident', 'host', 'cloak', '*');
        $context = new NickServContext($sender, null, 'VERIFY', ['token'], $notifier, $translator, 'en', 'UTC', 'NOTICE', new NickServCommandRegistry([]), new PendingVerificationRegistry(), new RecoveryTokenRegistry(), new ServiceNicknameRegistry([$provider]), $renderer);
        $command = new VerifyCommand($handler, verificationTokenTtlSeconds: 3600);
        $command->execute($context);
        $command->execute($context);
        $command->execute($context);
        $command->execute($context);
        self::assertSame(["\x0303✔\x03\x0F Verified \x02Alice\x03\x0F", "\x0304✗\x03\x0F Invalid token", "\x0303✔\x03\x0F Confirmed", "\x0304✗\x03\x0F Wait \x0260\x03\x0F"], $messages);
    }

    /**
     * @param list<mixed>  $messages
     * @param list<string> $args
     */
    private function createContext(
        ?SenderView $sender,
        array &$messages,
        array $args,
        bool $captureParams = false,
        ?NickServNotifierInterface $notifier = null,
    ): NickServContext {
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static function (string $key, array $params) use (&$messages, $captureParams): string {
            $messages[] = $captureParams ? [$key, $params] : $key;

            return $key;
        });

        return new NickServContext(
            $sender,
            null,
            'VERIFY',
            $args,
            $notifier ?? $this->createStub(NickServNotifierInterface::class),
            $translator,
            'es',
            'UTC',
            'NOTICE',
            new NickServCommandRegistry([]),
            new PendingVerificationRegistry(),
            new RecoveryTokenRegistry(),
            new ServiceNicknameRegistry([$this->nicknameProvider()]),
        );
    }

    private function nicknameProvider(): ServiceNicknameProviderInterface
    {
        return new class implements ServiceNicknameProviderInterface {
            public function getServiceKey(): string
            {
                return 'nickserv';
            }

            public function getNickname(): string
            {
                return 'NickServ';
            }
        };
    }
}
