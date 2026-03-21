<?php

declare(strict_types=1);

namespace App\Tests\Application\NickServ\Command\Handler;

use App\Application\ApplicationPort\ServiceNicknameProviderInterface;
use App\Application\ApplicationPort\ServiceNicknameRegistry;
use App\Application\NickServ\Command\Handler\SetEmailHandler;
use App\Application\NickServ\Command\NickServCommandRegistry;
use App\Application\NickServ\Command\NickServContext;
use App\Application\NickServ\Command\NickServNotifierInterface;
use App\Application\NickServ\PendingEmailChangeRegistry;
use App\Application\NickServ\PendingVerificationRegistry;
use App\Application\NickServ\RecoveryTokenRegistry;
use App\Application\Port\SenderView;
use App\Domain\NickServ\Entity\RegisteredNick;
use App\Domain\NickServ\Repository\RegisteredNickRepositoryInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

#[CoversClass(SetEmailHandler::class)]
final class SetEmailHandlerTest extends TestCase
{
    private function createContext(
        NickServNotifierInterface $notifier,
        TranslatorInterface $translator,
        string $value,
    ): NickServContext {
        return new NickServContext(
            new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip'),
            null,
            'SET',
            ['EMAIL', $value],
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

    #[Test]
    public function emptyValueRepliesSyntaxError(): void
    {
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $pending = new PendingEmailChangeRegistry();
        $messageBus = $this->createStub(MessageBusInterface::class);
        $translator = $this->createStub(TranslatorInterface::class);
        $logger = $this->createStub(LoggerInterface::class);
        $account = $this->createStub(RegisteredNick::class);

        $messages = [];
        $notifier = $this->createStub(NickServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $handler = new SetEmailHandler($nickRepo, $pending, $messageBus, $translator, $logger);
        $handler->handle($this->createContext($notifier, $translator, ''), $account, '');

        self::assertSame(['error.syntax'], $messages);
    }

    #[Test]
    public function invalidEmailRepliesInvalidEmail(): void
    {
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $pending = new PendingEmailChangeRegistry();
        $messageBus = $this->createStub(MessageBusInterface::class);
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);
        $logger = $this->createStub(LoggerInterface::class);
        $account = $this->createStub(RegisteredNick::class);

        $messages = [];
        $notifier = $this->createStub(NickServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });

        $handler = new SetEmailHandler($nickRepo, $pending, $messageBus, $translator, $logger);
        $handler->handle($this->createContext($notifier, $translator, 'not-an-email'), $account, 'not-an-email');

        self::assertSame(['register.invalid_email'], $messages);
    }

    #[Test]
    public function confirmWithInvalidTokenRepliesInvalidToken(): void
    {
        $account = $this->createStub(RegisteredNick::class);
        $account->method('getNickname')->willReturn('User');
        $account->method('getEmail')->willReturn('old@example.com');
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $pending = new PendingEmailChangeRegistry();
        $messageBus = $this->createStub(MessageBusInterface::class);
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);
        $logger = $this->createStub(LoggerInterface::class);

        $messages = [];
        $notifier = $this->createStub(NickServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });

        $handler = new SetEmailHandler($nickRepo, $pending, $messageBus, $translator, $logger);
        $handler->handle($this->createContext($notifier, $translator, 'new@example.com wrong-token'), $account, 'new@example.com wrong-token');

        self::assertSame(['set.email.invalid_token'], $messages);
    }

    #[Test]
    public function requestEmailChangeDispatchesAndRepliesPendingSent(): void
    {
        $account = $this->createStub(RegisteredNick::class);
        $account->method('getNickname')->willReturn('User');
        $account->method('getEmail')->willReturn('old@example.com');
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepo->method('findByEmail')->willReturn(null);
        $pending = new PendingEmailChangeRegistry();
        $dispatched = [];
        $messageBus = $this->createStub(MessageBusInterface::class);
        $messageBus->method('dispatch')->willReturnCallback(static function (object $m) use (&$dispatched): Envelope {
            $dispatched[] = $m;

            return new Envelope($m);
        });
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);
        $logger = $this->createStub(LoggerInterface::class);

        $messages = [];
        $notifier = $this->createStub(NickServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });

        $handler = new SetEmailHandler($nickRepo, $pending, $messageBus, $translator, $logger);
        $handler->handle($this->createContext($notifier, $translator, 'new@example.com'), $account, 'new@example.com');

        self::assertSame(['set.email.pending_sent'], $messages);
        self::assertCount(1, $dispatched);
    }

    #[Test]
    public function confirmEmailChangeSuccess(): void
    {
        $account = $this->createMock(RegisteredNick::class);
        $account->method('getNickname')->willReturn('User');
        $account->method('getEmail')->willReturn('old@example.com');
        $account->method('getId')->willReturn(1);
        $account->expects(self::once())->method('changeEmail')->with('new@example.com');
        $nickRepo = $this->createMock(RegisteredNickRepositoryInterface::class);
        $nickRepo->method('findByEmail')->willReturn(null);
        $nickRepo->expects(self::once())->method('save')->with($account);
        $pending = new PendingEmailChangeRegistry();
        $token = 'validtoken123';
        $pending->store('User', 'new@example.com', $token);
        $messageBus = $this->createStub(MessageBusInterface::class);
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);
        $logger = $this->createStub(LoggerInterface::class);

        $messages = [];
        $notifier = $this->createStub(NickServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });

        $handler = new SetEmailHandler($nickRepo, $pending, $messageBus, $translator, $logger);
        $handler->handle($this->createContext($notifier, $translator, 'new@example.com ' . $token), $account, 'new@example.com ' . $token);

        self::assertSame(['set.email.success'], $messages);
    }

    #[Test]
    public function confirmEmailChangeEmailAlreadyUsed(): void
    {
        $existingAccount = $this->createStub(RegisteredNick::class);
        $existingAccount->method('getId')->willReturn(2);
        $account = $this->createStub(RegisteredNick::class);
        $account->method('getNickname')->willReturn('User');
        $account->method('getEmail')->willReturn('old@example.com');
        $account->method('getId')->willReturn(1);
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepo->method('findByEmail')->willReturn($existingAccount);
        $pending = new PendingEmailChangeRegistry();
        $token = 'validtoken123';
        $pending->store('User', 'new@example.com', $token);
        $messageBus = $this->createStub(MessageBusInterface::class);
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);
        $logger = $this->createStub(LoggerInterface::class);

        $messages = [];
        $notifier = $this->createStub(NickServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });

        $handler = new SetEmailHandler($nickRepo, $pending, $messageBus, $translator, $logger);
        $handler->handle($this->createContext($notifier, $translator, 'new@example.com ' . $token), $account, 'new@example.com ' . $token);

        self::assertSame(['register.email_already_used'], $messages);
    }

    #[Test]
    public function accountWithoutEmail(): void
    {
        $account = $this->createStub(RegisteredNick::class);
        $account->method('getNickname')->willReturn('User');
        $account->method('getEmail')->willReturn(null);
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $pending = new PendingEmailChangeRegistry();
        $messageBus = $this->createStub(MessageBusInterface::class);
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);
        $logger = $this->createStub(LoggerInterface::class);

        $messages = [];
        $notifier = $this->createStub(NickServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });

        $handler = new SetEmailHandler($nickRepo, $pending, $messageBus, $translator, $logger);
        $handler->handle($this->createContext($notifier, $translator, 'new@example.com'), $account, 'new@example.com');

        self::assertSame(['error.not_identified'], $messages);
    }

    #[Test]
    public function requestEmailChangeEmailAlreadyUsedByOtherAccount(): void
    {
        $account = $this->createStub(RegisteredNick::class);
        $account->method('getNickname')->willReturn('User');
        $account->method('getEmail')->willReturn('old@example.com');

        $otherAccount = $this->createStub(RegisteredNick::class);
        $otherAccount->method('getNickname')->willReturn('OtherUser');

        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepo->method('findByEmail')->willReturn($otherAccount);
        $pending = new PendingEmailChangeRegistry();
        $messageBus = $this->createStub(MessageBusInterface::class);
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);
        $logger = $this->createStub(LoggerInterface::class);

        $messages = [];
        $notifier = $this->createStub(NickServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });

        $handler = new SetEmailHandler($nickRepo, $pending, $messageBus, $translator, $logger);
        $handler->handle($this->createContext($notifier, $translator, 'other@example.com'), $account, 'other@example.com');

        self::assertSame(['register.email_already_used'], $messages);
    }

    #[Test]
    public function requestEmailChangeDispatchExceptionRepliesMailFailed(): void
    {
        $account = $this->createStub(RegisteredNick::class);
        $account->method('getNickname')->willReturn('User');
        $account->method('getEmail')->willReturn('old@example.com');

        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepo->method('findByEmail')->willReturn(null);
        $pending = new PendingEmailChangeRegistry();

        $messageBus = $this->createStub(MessageBusInterface::class);
        $messageBus->method('dispatch')->willThrowException(new RuntimeException('Mail server offline'));

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('error')
            ->with('NickServ SET EMAIL: failed to dispatch token email', self::anything());

        $messages = [];
        $notifier = $this->createStub(NickServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });

        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $handler = new SetEmailHandler($nickRepo, $pending, $messageBus, $translator, $logger);
        $handler->handle($this->createContext($notifier, $translator, 'new@example.com'), $account, 'new@example.com');

        self::assertSame(['error.mail_failed'], $messages);
    }

    #[Test]
    public function requestEmailChangeDefensiveNullEmailInMethod(): void
    {
        $account = $this->createConfiguredStub(RegisteredNick::class, [
            'getNickname' => 'User',
        ]);
        $account->method('getEmail')->willReturnOnConsecutiveCalls('valid@example.com', null);

        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepo->method('findByEmail')->willReturn(null);
        $pending = new PendingEmailChangeRegistry();
        $messageBus = $this->createStub(MessageBusInterface::class);
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);
        $logger = $this->createStub(LoggerInterface::class);

        $messages = [];
        $notifier = $this->createStub(NickServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });

        $handler = new SetEmailHandler($nickRepo, $pending, $messageBus, $translator, $logger);
        $handler->handle($this->createContext($notifier, $translator, 'new@example.com'), $account, 'new@example.com');

        self::assertSame(['error.not_identified'], $messages);
    }

    private function createServiceNicks(): ServiceNicknameRegistry
    {
        $provider1 = new class('nickserv', 'NickServ') implements ServiceNicknameProviderInterface {
            public function __construct(private string $key, private string $nick)
            {
            }

            public function getServiceKey(): string
            {
                return $this->key;
            }

            public function getNickname(): string
            {
                return $this->nick;
            }
        };
        $provider2 = new class('chanserv', 'ChanServ') implements ServiceNicknameProviderInterface {
            public function __construct(private string $key, private string $nick)
            {
            }

            public function getServiceKey(): string
            {
                return $this->key;
            }

            public function getNickname(): string
            {
                return $this->nick;
            }
        };
        $provider3 = new class('memoserv', 'MemoServ') implements ServiceNicknameProviderInterface {
            public function __construct(private string $key, private string $nick)
            {
            }

            public function getServiceKey(): string
            {
                return $this->key;
            }

            public function getNickname(): string
            {
                return $this->nick;
            }
        };
        $provider4 = new class('operserv', 'OperServ') implements ServiceNicknameProviderInterface {
            public function __construct(private string $key, private string $nick)
            {
            }

            public function getServiceKey(): string
            {
                return $this->key;
            }

            public function getNickname(): string
            {
                return $this->nick;
            }
        };

        return new ServiceNicknameRegistry([$provider1, $provider2, $provider3, $provider4]);
    }
}
