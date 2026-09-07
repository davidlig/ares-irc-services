<?php

declare(strict_types=1);

namespace App\Tests\NickServ\Adapter\In\Irc\Command;

use App\Application\Port\AsyncMessageDispatcherInterface;
use App\Application\Port\EventBusInterface;
use App\Application\Port\TranslationInterface;
use App\Irc\Application\Port\In\SenderView;
use App\NickServ\Adapter\In\Irc\Command\SetEmailHandler;
use App\NickServ\Adapter\In\Irc\NickServCommandRegistry;
use App\NickServ\Adapter\In\Irc\NickServContext;
use App\NickServ\Adapter\In\Irc\NickServNotifierInterface;
use App\NickServ\Adapter\Out\InMemory\PendingEmailChangeRegistry;
use App\NickServ\Adapter\Out\InMemory\PendingVerificationRegistry;
use App\NickServ\Adapter\Out\InMemory\RecoveryTokenRegistry;
use App\NickServ\Application\Event\NickEmailChangedEvent;
use App\NickServ\Application\Port\Out\Clock;
use App\NickServ\Application\Port\Out\RegisteredNickRepositoryInterface;
use App\NickServ\Application\Port\Out\VerificationTokenGenerator;
use App\NickServ\Domain\Entity\RegisteredNick;
use App\Shared\Application\Port\Out\ServiceNicknameProviderInterface;
use App\Shared\Application\ServiceNicknameRegistry;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Symfony\Component\Messenger\Envelope;

#[CoversClass(SetEmailHandler::class)]
final class SetEmailHandlerTest extends TestCase
{
    private function createContext(
        NickServNotifierInterface $notifier,
        TranslationInterface $translator,
        string $value,
        bool $withoutSender = false,
    ): NickServContext {
        return new NickServContext(
            $withoutSender ? null : new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip'),
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
        $messageBus = $this->createStub(AsyncMessageDispatcherInterface::class);
        $translator = $this->createStub(TranslationInterface::class);
        $logger = $this->createStub(LoggerInterface::class);
        $account = $this->createStub(RegisteredNick::class);

        $messages = [];
        $notifier = $this->createStub(NickServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $handler = new SetEmailHandler(
            $nickRepo,
            $pending,
            $messageBus,
            $this->createStub(VerificationTokenGenerator::class),
            $this->clock(),
            $translator,
            $logger,
            $this->createStub(EventBusInterface::class)
        );
        $handler->handle($this->createContext($notifier, $translator, ''), $account, '');

        self::assertSame(['error.syntax'], $messages);
    }

    #[Test]
    public function invalidEmailRepliesInvalidEmail(): void
    {
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $pending = new PendingEmailChangeRegistry();
        $messageBus = $this->createStub(AsyncMessageDispatcherInterface::class);
        $translator = $this->createStub(TranslationInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);
        $logger = $this->createStub(LoggerInterface::class);
        $account = $this->createStub(RegisteredNick::class);

        $messages = [];
        $notifier = $this->createStub(NickServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });

        $handler = new SetEmailHandler(
            $nickRepo,
            $pending,
            $messageBus,
            $this->createStub(VerificationTokenGenerator::class),
            $this->clock(),
            $translator,
            $logger,
            $this->createStub(EventBusInterface::class)
        );
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
        $messageBus = $this->createStub(AsyncMessageDispatcherInterface::class);
        $translator = $this->createStub(TranslationInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);
        $logger = $this->createStub(LoggerInterface::class);

        $messages = [];
        $notifier = $this->createStub(NickServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });

        $handler = new SetEmailHandler(
            $nickRepo,
            $pending,
            $messageBus,
            $this->createStub(VerificationTokenGenerator::class),
            $this->clock(),
            $translator,
            $logger,
            $this->createStub(EventBusInterface::class)
        );
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
        $messageBus = $this->createStub(AsyncMessageDispatcherInterface::class);
        $messageBus->method('dispatch')->willReturnCallback(static function (object $m) use (&$dispatched): Envelope {
            $dispatched[] = $m;

            return new Envelope($m);
        });

        $translatorCalls = [];
        $translator = $this->createStub(TranslationInterface::class);
        $translator->method('trans')->willReturnCallback(static function (string $id, array $params = [], string $domain = 'nickserv', string $locale = 'en') use (&$translatorCalls): string {
            $translatorCalls[] = ['id' => $id, 'params' => $params, 'domain' => $domain, 'locale' => $locale];

            return $id;
        });
        $logger = $this->createStub(LoggerInterface::class);

        $messages = [];
        $notifier = $this->createStub(NickServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $notifier->method('getNick')->willReturn('NickServ');

        $handler = new SetEmailHandler(
            $nickRepo,
            $pending,
            $messageBus,
            $this->createStub(VerificationTokenGenerator::class),
            $this->clock(),
            $translator,
            $logger,
            $this->createStub(EventBusInterface::class)
        );
        $handler->handle($this->createContext($notifier, $translator, 'new@example.com'), $account, 'new@example.com');

        self::assertSame(['set.email.pending_sent'], $messages);
        self::assertCount(1, $dispatched);

        $emailSubjectCalls = array_filter($translatorCalls, static fn (array $c): bool => 'email_change_token_subject' === $c['id']);
        self::assertCount(1, $emailSubjectCalls, 'Subject translation should be called once');
        $subjectCall = reset($emailSubjectCalls);
        self::assertSame('mail', $subjectCall['domain']);
        self::assertArrayHasKey('%bot%', $subjectCall['params']);
        self::assertSame('NickServ', $subjectCall['params']['%bot%']);
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
        $pending->store('User', 'new@example.com', $token, $this->now());
        $messageBus = $this->createStub(AsyncMessageDispatcherInterface::class);
        $translator = $this->createStub(TranslationInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);
        $logger = $this->createStub(LoggerInterface::class);

        $messages = [];
        $notifier = $this->createStub(NickServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });

        $handler = new SetEmailHandler(
            $nickRepo,
            $pending,
            $messageBus,
            $this->createStub(VerificationTokenGenerator::class),
            $this->clock(),
            $translator,
            $logger,
            $this->createStub(EventBusInterface::class)
        );
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
        $pending->store('User', 'new@example.com', $token, $this->now());
        $messageBus = $this->createStub(AsyncMessageDispatcherInterface::class);
        $translator = $this->createStub(TranslationInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);
        $logger = $this->createStub(LoggerInterface::class);

        $messages = [];
        $notifier = $this->createStub(NickServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });

        $handler = new SetEmailHandler(
            $nickRepo,
            $pending,
            $messageBus,
            $this->createStub(VerificationTokenGenerator::class),
            $this->clock(),
            $translator,
            $logger,
            $this->createStub(EventBusInterface::class)
        );
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
        $messageBus = $this->createStub(AsyncMessageDispatcherInterface::class);
        $translator = $this->createStub(TranslationInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);
        $logger = $this->createStub(LoggerInterface::class);

        $messages = [];
        $notifier = $this->createStub(NickServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });

        $handler = new SetEmailHandler(
            $nickRepo,
            $pending,
            $messageBus,
            $this->createStub(VerificationTokenGenerator::class),
            $this->clock(),
            $translator,
            $logger,
            $this->createStub(EventBusInterface::class)
        );
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
        $messageBus = $this->createStub(AsyncMessageDispatcherInterface::class);
        $translator = $this->createStub(TranslationInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);
        $logger = $this->createStub(LoggerInterface::class);

        $messages = [];
        $notifier = $this->createStub(NickServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });

        $handler = new SetEmailHandler(
            $nickRepo,
            $pending,
            $messageBus,
            $this->createStub(VerificationTokenGenerator::class),
            $this->clock(),
            $translator,
            $logger,
            $this->createStub(EventBusInterface::class)
        );
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

        $messageBus = $this->createStub(AsyncMessageDispatcherInterface::class);
        $messageBus->method('dispatch')->willThrowException(new RuntimeException('Mail server offline'));

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('error')
            ->with('NickServ SET EMAIL: failed to dispatch token email', self::anything());

        $messages = [];
        $notifier = $this->createStub(NickServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });

        $translator = $this->createStub(TranslationInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $handler = new SetEmailHandler(
            $nickRepo,
            $pending,
            $messageBus,
            $this->createStub(VerificationTokenGenerator::class),
            $this->clock(),
            $translator,
            $logger,
            $this->createStub(EventBusInterface::class)
        );
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
        $messageBus = $this->createStub(AsyncMessageDispatcherInterface::class);
        $translator = $this->createStub(TranslationInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);
        $logger = $this->createStub(LoggerInterface::class);

        $messages = [];
        $notifier = $this->createStub(NickServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });

        $handler = new SetEmailHandler(
            $nickRepo,
            $pending,
            $messageBus,
            $this->createStub(VerificationTokenGenerator::class),
            $this->clock(),
            $translator,
            $logger,
            $this->createStub(EventBusInterface::class)
        );
        $handler->handle($this->createContext($notifier, $translator, 'new@example.com'), $account, 'new@example.com');

        self::assertSame(['error.not_identified'], $messages);
    }

    #[Test]
    public function confirmedChangeWithoutSenderStopsBeforeUpdatingEmail(): void
    {
        $account = $this->createMock(RegisteredNick::class);
        $account->method('getNickname')->willReturn('TestNick');
        $account->method('getEmail')->willReturn('old@example.com');
        $account->expects(self::never())->method('changeEmail');
        $pending = new PendingEmailChangeRegistry();
        $pending->store('TestNick', 'new@example.com', 'token', $this->now());
        $nickRepository = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepository->method('findByEmail')->willReturn(null);

        $handler = new SetEmailHandler(
            $nickRepository,
            $pending,
            $this->createStub(AsyncMessageDispatcherInterface::class),
            $this->createStub(VerificationTokenGenerator::class),
            $this->clock(),
            $this->createStub(TranslationInterface::class),
            $this->createStub(LoggerInterface::class),
            $this->createStub(EventBusInterface::class),
        );

        $handler->handle(
            $this->createContext($this->createStub(NickServNotifierInterface::class), $this->createStub(TranslationInterface::class), 'new@example.com token', true),
            $account,
            'new@example.com token',
        );
    }

    #[Test]
    public function directChangeWithoutSenderStopsBeforeUpdatingEmail(): void
    {
        $account = $this->createMock(RegisteredNick::class);
        $account->method('getEmail')->willReturn('old@example.com');
        $account->expects(self::never())->method('changeEmail');
        $nickRepository = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepository->method('findByEmail')->willReturn(null);

        $handler = new SetEmailHandler(
            $nickRepository,
            new PendingEmailChangeRegistry(),
            $this->createStub(AsyncMessageDispatcherInterface::class),
            $this->createStub(VerificationTokenGenerator::class),
            $this->clock(),
            $this->createStub(TranslationInterface::class),
            $this->createStub(LoggerInterface::class),
            $this->createStub(EventBusInterface::class),
        );

        $handler->handle(
            $this->createContext($this->createStub(NickServNotifierInterface::class), $this->createStub(TranslationInterface::class), 'new@example.com', true),
            $account,
            'new@example.com',
            isIrcopMode: true,
        );
    }

    private function createServiceNicks(): ServiceNicknameRegistry
    {
        $provider1 = new class('nickserv', 'NickServ') implements ServiceNicknameProviderInterface {
            public function __construct(private string $key, private string $nick) {}

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
            public function __construct(private string $key, private string $nick) {}

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
            public function __construct(private string $key, private string $nick) {}

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
            public function __construct(private string $key, private string $nick) {}

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

    #[Test]
    public function handleWithEmptyIpDispatchesEventWithAsteriskIp(): void
    {
        $account = $this->createStub(RegisteredNick::class);
        $account->method('getId')->willReturn(1);
        $account->method('getEmail')->willReturn('old@example.com');

        $nickRepo = $this->createMock(RegisteredNickRepositoryInterface::class);
        $nickRepo->method('findByEmail')->willReturn(null);
        $nickRepo->expects(self::once())->method('save')->with($account);

        $dispatchedEvents = [];
        $eventDispatcher = $this->createMock(EventBusInterface::class);
        $eventDispatcher->expects(self::once())
            ->method('dispatch')
            ->willReturnCallback(static function (object $event) use (&$dispatchedEvents): object {
                $dispatchedEvents[] = $event;

                return $event;
            });

        $handler = new SetEmailHandler(
            $nickRepo,
            new PendingEmailChangeRegistry(),
            $this->createStub(AsyncMessageDispatcherInterface::class),
            $this->createStub(VerificationTokenGenerator::class),
            $this->clock(),
            $this->createStub(TranslationInterface::class),
            $this->createStub(LoggerInterface::class),
            $eventDispatcher,
        );

        $messages = [];
        $notifier = $this->createStub(NickServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslationInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $context = new NickServContext(
            new SenderView('UID1', 'User', 'i', 'h', 'c', ''),
            null,
            'SET',
            ['EMAIL', 'new@example.com'],
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

        $handler->handle($context, $account, 'new@example.com', true);

        self::assertCount(1, $dispatchedEvents);
        self::assertInstanceOf(NickEmailChangedEvent::class, $dispatchedEvents[0]);
        self::assertSame('*', $dispatchedEvents[0]->performedByIp);
    }

    #[Test]
    public function handleWithInvalidBase64IpDispatchesEventWithOriginalIp(): void
    {
        $account = $this->createStub(RegisteredNick::class);
        $account->method('getId')->willReturn(1);
        $account->method('getEmail')->willReturn('old@example.com');

        $nickRepo = $this->createMock(RegisteredNickRepositoryInterface::class);
        $nickRepo->method('findByEmail')->willReturn(null);
        $nickRepo->expects(self::once())->method('save')->with($account);

        $dispatchedEvents = [];
        $eventDispatcher = $this->createMock(EventBusInterface::class);
        $eventDispatcher->expects(self::once())
            ->method('dispatch')
            ->willReturnCallback(static function (object $event) use (&$dispatchedEvents): object {
                $dispatchedEvents[] = $event;

                return $event;
            });

        $handler = new SetEmailHandler(
            $nickRepo,
            new PendingEmailChangeRegistry(),
            $this->createStub(AsyncMessageDispatcherInterface::class),
            $this->createStub(VerificationTokenGenerator::class),
            $this->clock(),
            $this->createStub(TranslationInterface::class),
            $this->createStub(LoggerInterface::class),
            $eventDispatcher,
        );

        $messages = [];
        $notifier = $this->createStub(NickServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslationInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $context = new NickServContext(
            new SenderView('UID1', 'User', 'i', 'h', 'c', 'invalid!base64'),
            null,
            'SET',
            ['EMAIL', 'new@example.com'],
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

        $handler->handle($context, $account, 'new@example.com', true);

        self::assertCount(1, $dispatchedEvents);
        self::assertInstanceOf(NickEmailChangedEvent::class, $dispatchedEvents[0]);
        self::assertSame('invalid!base64', $dispatchedEvents[0]->performedByIp);
    }

    private function clock(): Clock
    {
        $clock = $this->createStub(Clock::class);
        $clock->method('now')->willReturn($this->now());

        return $clock;
    }

    private function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-09-06 12:00:00 UTC');
    }
}
