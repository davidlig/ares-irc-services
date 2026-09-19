<?php

declare(strict_types=1);

namespace App\Tests\NickServ\Adapter\In\Irc\Command;

use App\Irc\Application\Port\In\SenderView;
use App\Irc\Application\Port\In\ServiceNicknameProviderInterface;
use App\Irc\Application\Port\In\ServiceNicknameRegistry;
use App\NickServ\Adapter\In\Irc\Command\RegisterCommand;
use App\NickServ\Adapter\In\Irc\NickServCommandRegistry;
use App\NickServ\Adapter\In\Irc\NickServContext;
use App\NickServ\Adapter\In\Irc\NickServNotifierInterface;
use App\NickServ\Adapter\Out\InMemory\PendingVerificationRegistry;
use App\NickServ\Adapter\Out\InMemory\RecoveryTokenRegistry;
use App\NickServ\Application\UseCase\Register\RegisterNick;
use App\NickServ\Application\UseCase\Register\RegisterNickHandlerInterface;
use App\NickServ\Application\UseCase\Register\RegisterNickResult;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

#[CoversClass(RegisterCommand::class)]
final class RegisterCommandTest extends TestCase
{
    #[Test]
    public function exposesRegisterMetadata(): void
    {
        $command = new RegisterCommand($this->createStub(RegisterNickHandlerInterface::class));

        self::assertSame('REGISTER', $command->getName());
        self::assertSame([], $command->getAliases());
        self::assertSame(2, $command->getMinArgs());
        self::assertSame('register.syntax', $command->getSyntaxKey());
        self::assertSame('register.help', $command->getHelpKey());
        self::assertSame(1, $command->getOrder());
        self::assertSame('register.short', $command->getShortDescKey());
        self::assertSame([], $command->getSubCommandHelp());
        self::assertFalse($command->isOperOnly());
        self::assertNull($command->getRequiredPermission());
        self::assertSame([], $command->getHelpParams());
    }

    /** @param array{uid: string, nick: string, ident: string, hostname: string, cloakedHost: string, ipBase64: string} $senderData */
    #[Test]
    #[DataProvider('clientKeys')]
    public function mapsIrcInputToTypedUseCaseInput(array $senderData, string $expectedClientKey): void
    {
        $sender = new SenderView(...$senderData);
        $handler = $this->createMock(RegisterNickHandlerInterface::class);
        $handler->expects(self::once())->method('handle')->with(self::callback(
            static fn (RegisterNick $input): bool => 'CurrentNick' === $input->nickname
                && 'plain-secret' === $input->password
                && 'user@example.com' === $input->email
                && 'es' === $input->language
                && $expectedClientKey === $input->clientKey,
        ))->willReturn(RegisterNickResult::verificationRequired('user@example.com'));

        $messages = [];
        $command = new RegisterCommand($handler);
        $command->execute($this->context($sender, $messages));

        self::assertSame(['register.pending'], $messages);
    }

    /**
     * @return iterable<string, array{array{uid: string, nick: string, ident: string, hostname: string, cloakedHost: string, ipBase64: string}, string}>
     */
    public static function clientKeys(): iterable
    {
        yield 'IP has priority' => [[
            'uid' => 'UID1', 'nick' => 'CurrentNick', 'ident' => 'ident', 'hostname' => 'host', 'cloakedHost' => 'cloak', 'ipBase64' => 'encoded-ip',
        ], 'ip:encoded-ip'];
        yield 'cloak is the first fallback' => [[
            'uid' => 'UID1', 'nick' => 'CurrentNick', 'ident' => 'ident', 'hostname' => 'host', 'cloakedHost' => 'cloak', 'ipBase64' => '*',
        ], 'cloak:cloak'];
        yield 'hostname follows cloak' => [[
            'uid' => 'UID1', 'nick' => 'CurrentNick', 'ident' => 'ident', 'hostname' => 'host', 'cloakedHost' => '', 'ipBase64' => '',
        ], 'host:host'];
        yield 'UID is the final fallback' => [[
            'uid' => 'UID1', 'nick' => 'CurrentNick', 'ident' => 'ident', 'hostname' => '', 'cloakedHost' => '', 'ipBase64' => '*',
        ], 'uid:UID1'];
    }

    /** @param array<string, mixed> $expectedParams */
    #[Test]
    #[DataProvider('presentations')]
    public function presentsSemanticResults(RegisterNickResult $result, string $expectedKey, array $expectedParams): void
    {
        $handler = $this->createStub(RegisterNickHandlerInterface::class);
        $handler->method('handle')->willReturn($result);
        /** @var list<array{0: string, 1: array<string, mixed>}> $calls */
        $calls = [];

        $command = new RegisterCommand($handler);
        $command->execute($this->context(
            new SenderView('UID1', 'CurrentNick', 'ident', 'host', 'cloak', 'ip'),
            $calls,
            captureParams: true,
        ));

        self::assertCount(1, $calls);
        $call = $calls[0];
        self::assertIsArray($call);
        self::assertSame($expectedKey, $call[0]);
        $params = $call[1];
        self::assertIsArray($params);
        foreach ($expectedParams as $name => $value) {
            self::assertSame($value, $params[$name] ?? null);
        }
    }

    /** @return iterable<string, array{RegisterNickResult, string, array<string, mixed>}> */
    public static function presentations(): iterable
    {
        yield 'verification required' => [RegisterNickResult::verificationRequired('a@b.test'), 'register.pending', ['%email%' => 'a@b.test']];
        yield 'throttled rounds up minutes' => [RegisterNickResult::throttled(61), 'register.throttled', ['%minutes%' => '2']];
        yield 'guest prefix' => [RegisterNickResult::guestPrefixForbidden('Guest-'), 'register.guest_prefix_forbidden', ['%prefix%' => 'Guest-']];
        yield 'invalid email' => [RegisterNickResult::invalidEmail(), 'register.invalid_email', []];
        yield 'email used' => [RegisterNickResult::emailAlreadyUsed('a@b.test'), 'register.email_already_used', ['%email%' => 'a@b.test']];
        yield 'already pending' => [RegisterNickResult::alreadyPending('Nick'), 'register.already_pending', ['%nickname%' => 'Nick']];
        yield 'forbidden' => [RegisterNickResult::forbidden('Nick'), 'register.forbidden', ['%nickname%' => 'Nick']];
        yield 'pending deletion' => [RegisterNickResult::pendingDeletion('Nick'), 'register.pending_deletion', ['%nickname%' => 'Nick']];
        yield 'already registered' => [RegisterNickResult::alreadyRegistered('Nick'), 'register.already_registered', ['%nickname%' => 'Nick']];
        yield 'mail failed' => [RegisterNickResult::mailDeliveryFailed(), 'error.mail_failed', []];
    }

    #[Test]
    public function ignoresMissingSender(): void
    {
        $handler = $this->createMock(RegisterNickHandlerInterface::class);
        $handler->expects(self::never())->method('handle');
        $messages = [];

        new RegisterCommand($handler)->execute($this->context(null, $messages));

        self::assertSame([], $messages);
    }

    /** @param list<mixed> $messages */
    private function context(?SenderView $sender, array &$messages, bool $captureParams = false): NickServContext
    {
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static function (string $key, array $params) use (&$messages, $captureParams): string {
            $messages[] = $captureParams ? [$key, $params] : $key;

            return $key;
        });
        $notifier = $this->createStub(NickServNotifierInterface::class);
        $notifier->method('getNick')->willReturn('NickServ');

        return new NickServContext(
            $sender,
            null,
            'REGISTER',
            ['plain-secret', 'user@example.com'],
            $notifier,
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
