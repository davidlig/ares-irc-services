<?php

declare(strict_types=1);

namespace App\Tests\NickServ\Adapter\In\Irc\Command;

use App\Application\Port\EventBusInterface;
use App\Application\Port\TranslationInterface;
use App\Irc\Application\Port\In\SenderView;
use App\NickServ\Adapter\In\Irc\Command\SetPasswordHandler;
use App\NickServ\Adapter\In\Irc\NickServCommandRegistry;
use App\NickServ\Adapter\In\Irc\NickServContext;
use App\NickServ\Adapter\In\Irc\NickServNotifierInterface;
use App\NickServ\Adapter\Out\InMemory\PendingVerificationRegistry;
use App\NickServ\Adapter\Out\InMemory\RecoveryTokenRegistry;
use App\NickServ\Application\Port\Out\PasswordHasher;
use App\NickServ\Application\Port\Out\RegisteredNickRepositoryInterface;
use App\NickServ\Application\PublishedEvent\NickPasswordHashAvailable;
use App\NickServ\Domain\Entity\RegisteredNick;
use App\NickServ\Domain\Event\NickPasswordChangedEvent;
use App\Shared\Application\Port\Out\ServiceNicknameProviderInterface;
use App\Shared\Application\ServiceNicknameRegistry;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(SetPasswordHandler::class)]
final class SetPasswordHandlerTest extends TestCase
{
    private function createContext(
        NickServNotifierInterface $notifier,
        TranslationInterface $translator,
        bool $withoutSender = false,
    ): NickServContext {
        return new NickServContext(
            $withoutSender ? null : new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip'),
            null,
            'SET',
            ['PASSWORD', ''],
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
        $passwordHasher = $this->createStub(PasswordHasher::class);
        $account = $this->createStub(RegisteredNick::class);

        $messages = [];
        $notifier = $this->createStub(NickServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslationInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $handler = new SetPasswordHandler($nickRepo, $passwordHasher, $this->createStub(EventBusInterface::class));
        $handler->handle($this->createContext($notifier, $translator), $account, '');

        self::assertSame(['error.syntax'], $messages);
    }

    #[Test]
    public function validValueChangesPasswordAndRepliesSuccess(): void
    {
        $account = $this->createMock(RegisteredNick::class);
        $account->expects(self::once())->method('changePassword')->with('newhash');
        $nickRepo = $this->createMock(RegisteredNickRepositoryInterface::class);
        $nickRepo->expects(self::once())->method('save')->with($account);
        $passwordHasher = $this->createStub(PasswordHasher::class);
        $passwordHasher->method('hash')->willReturn('newhash');

        $messages = [];
        $notifier = $this->createStub(NickServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslationInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $handler = new SetPasswordHandler($nickRepo, $passwordHasher, $this->createStub(EventBusInterface::class));
        $handler->handle($this->createContext($notifier, $translator), $account, 'newpass');

        self::assertSame(['set.password.success'], $messages);
    }

    #[Test]
    public function validValueWithoutSenderStopsAfterSaving(): void
    {
        $account = $this->createMock(RegisteredNick::class);
        $account->expects(self::once())->method('changePassword');
        $nickRepository = $this->createMock(RegisteredNickRepositoryInterface::class);
        $nickRepository->expects(self::once())->method('save')->with($account);
        $eventBus = $this->createMock(EventBusInterface::class);
        $eventBus->expects(self::never())->method('dispatch');

        $handler = new SetPasswordHandler($nickRepository, $this->createStub(PasswordHasher::class), $eventBus);
        $handler->handle(
            $this->createContext($this->createStub(NickServNotifierInterface::class), $this->createStub(TranslationInterface::class), true),
            $account,
            'newpass',
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
        $account = $this->createMock(RegisteredNick::class);
        $account->method('getId')->willReturn(1);
        $account->expects(self::once())->method('changePassword');

        $nickRepo = $this->createMock(RegisteredNickRepositoryInterface::class);
        $nickRepo->expects(self::once())->method('save')->with($account);

        $dispatchedEvents = [];
        $eventDispatcher = $this->createMock(EventBusInterface::class);
        $eventDispatcher->expects(self::exactly(2))
            ->method('dispatch')
            ->willReturnCallback(static function (object $event) use (&$dispatchedEvents): object {
                $dispatchedEvents[] = $event;

                return $event;
            });

        $handler = new SetPasswordHandler(
            $nickRepo,
            $this->createStub(PasswordHasher::class),
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
            ['PASSWORD', 'newpass'],
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

        $handler->handle($context, $account, 'newpass', true);

        self::assertCount(2, $dispatchedEvents);
        self::assertInstanceOf(NickPasswordHashAvailable::class, $dispatchedEvents[0]);
        self::assertInstanceOf(NickPasswordChangedEvent::class, $dispatchedEvents[1]);
        self::assertSame('*', $dispatchedEvents[1]->performedByIp);
    }

    #[Test]
    public function handleWithInvalidBase64IpDispatchesEventWithOriginalIp(): void
    {
        $account = $this->createMock(RegisteredNick::class);
        $account->method('getId')->willReturn(1);
        $account->expects(self::once())->method('changePassword');

        $nickRepo = $this->createMock(RegisteredNickRepositoryInterface::class);
        $nickRepo->expects(self::once())->method('save')->with($account);

        $dispatchedEvents = [];
        $eventDispatcher = $this->createMock(EventBusInterface::class);
        $eventDispatcher->expects(self::exactly(2))
            ->method('dispatch')
            ->willReturnCallback(static function (object $event) use (&$dispatchedEvents): object {
                $dispatchedEvents[] = $event;

                return $event;
            });

        $handler = new SetPasswordHandler(
            $nickRepo,
            $this->createStub(PasswordHasher::class),
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
            ['PASSWORD', 'newpass'],
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

        $handler->handle($context, $account, 'newpass', true);

        self::assertCount(2, $dispatchedEvents);
        self::assertInstanceOf(NickPasswordHashAvailable::class, $dispatchedEvents[0]);
        self::assertInstanceOf(NickPasswordChangedEvent::class, $dispatchedEvents[1]);
        self::assertSame('invalid!base64', $dispatchedEvents[1]->performedByIp);
    }
}
