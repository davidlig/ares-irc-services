<?php

declare(strict_types=1);

namespace App\Tests\NickServ\Application\Service;

use App\Application\Port\EventBusInterface;
use App\Application\Port\ServiceDebugNotifierInterface;
use App\Application\Port\TransactionManagerInterface;
use App\Irc\Application\Port\In\NetworkUserLookupPort;
use App\Irc\Application\Port\In\SenderView;
use App\NickServ\Adapter\Out\InMemory\IdentifiedSessionRegistry;
use App\NickServ\Application\Port\Out\RegisteredNickRepositoryInterface;
use App\NickServ\Application\Service\NickDropService;
use App\NickServ\Application\Service\NickForceService;
use App\NickServ\Domain\Entity\RegisteredNick;
use App\NickServ\Domain\Event\NickDropCleanupEvent;
use App\NickServ\Domain\Event\NickDropEvent;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionClass;

#[CoversClass(NickDropService::class)]
final class NickDropServiceTest extends TestCase
{
    #[Test]
    public function dropNickWithOfflineUserDropsSuccessfully(): void
    {
        $nick = $this->createNickWithId('TestNick', 42);

        $nickRepository = $this->createMock(RegisteredNickRepositoryInterface::class);
        $nickRepository->expects(self::once())->method('delete')->with($nick);

        $userLookup = $this->createStub(NetworkUserLookupPort::class);
        $userLookup->method('findByNick')->willReturn(null);

        $forceService = $this->createMock(NickForceService::class);
        $forceService->expects(self::never())->method('forceGuestNick');

        $calls = [];
        $eventDispatcher = $this->createMock(EventBusInterface::class);
        $eventDispatcher->expects(self::exactly(2))->method('dispatch')->willReturnCallback(
            static function (NickDropCleanupEvent|NickDropEvent $event) use (&$calls): void {
                $calls[] = match ($event::class) {
                    NickDropCleanupEvent::class => 'cleanup',
                    NickDropEvent::class => 'post-commit',
                };

                self::assertSame(42, $event->nickId);
                self::assertSame('TestNick', $event->nickname);
                self::assertSame('manual', $event->reason);
            },
        );

        $nickRepository->method('delete')->willReturnCallback(static function () use (&$calls): void {
            $calls[] = 'delete';
        });

        $transactionManager = $this->createMock(TransactionManagerInterface::class);
        $transactionManager->expects(self::once())->method('transactional')->willReturnCallback(
            static function (callable $operation) use (&$calls): mixed {
                $calls[] = 'transaction-start';
                $result = $operation();
                $calls[] = 'commit';

                return $result;
            },
        );

        $debug = $this->createMock(ServiceDebugNotifierInterface::class);
        $debug->expects(self::once())->method('log')->with(
            'OperUser',
            'DROP',
            'TestNick',
            null,
            null,
            'manual',
        );

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('info');

        $service = new NickDropService(
            $nickRepository,
            $userLookup,
            $forceService,
            $eventDispatcher,
            $debug,
            $logger,
            new IdentifiedSessionRegistry(),
            $transactionManager,
            'Guest-',
        );

        $service->dropNick($nick, 'manual', 'OperUser');

        self::assertSame(['transaction-start', 'cleanup', 'delete', 'commit', 'post-commit'], $calls);
    }

    #[Test]
    public function dropNickWithOnlineUserForcesRenameThenDrops(): void
    {
        $nick = $this->createNickWithId('OnlineNick', 100);

        $onlineUser = new SenderView('UID123', 'OnlineNick', 'i', 'h', 'c', 'ip', false, false, 'SID1', 'h', 'o');

        $nickRepository = $this->createMock(RegisteredNickRepositoryInterface::class);
        $nickRepository->expects(self::once())->method('delete')->with($nick);

        $userLookup = $this->createStub(NetworkUserLookupPort::class);
        $userLookup->method('findByNick')->willReturn($onlineUser);

        $forceService = $this->createMock(NickForceService::class);
        $forceService->expects(self::once())->method('forceGuestNick')->with('UID123', null, 'nick-drop');

        $eventDispatcher = $this->createMock(EventBusInterface::class);
        $eventDispatcher->expects(self::exactly(2))->method('dispatch');

        $debug = $this->createMock(ServiceDebugNotifierInterface::class);
        $debug->expects(self::once())->method('log');

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('info');

        $service = new NickDropService(
            $nickRepository,
            $userLookup,
            $forceService,
            $eventDispatcher,
            $debug,
            $logger,
            new IdentifiedSessionRegistry(),
            $this->immediateTransactionManager(),
            'Guest-',
        );

        $service->dropNick($nick, 'manual', 'OperUser');
    }

    #[Test]
    public function dropNickWithInactivityReasonLogsToDebugWithAsteriskOperator(): void
    {
        $nick = $this->createNickWithId('InactiveNick', 200);

        $nickRepository = $this->createMock(RegisteredNickRepositoryInterface::class);
        $nickRepository->expects(self::once())->method('delete');

        $userLookup = $this->createStub(NetworkUserLookupPort::class);
        $userLookup->method('findByNick')->willReturn(null);

        $forceService = $this->createMock(NickForceService::class);
        $forceService->expects(self::never())->method('forceGuestNick');

        $eventDispatcher = $this->createMock(EventBusInterface::class);
        $eventDispatcher->expects(self::exactly(2))->method('dispatch')->with(self::callback(static fn (object $event): bool => ($event instanceof NickDropCleanupEvent || $event instanceof NickDropEvent)
            && 'inactivity' === $event->reason));

        $debug = $this->createMock(ServiceDebugNotifierInterface::class);
        $debug->expects(self::once())->method('log')->with(
            '*',
            'DROP',
            'InactiveNick',
            null,
            null,
            'inactivity',
            self::anything(),
        );

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('info');

        $service = new NickDropService(
            $nickRepository,
            $userLookup,
            $forceService,
            $eventDispatcher,
            $debug,
            $logger,
            new IdentifiedSessionRegistry(),
            $this->immediateTransactionManager(),
            'Guest-',
        );

        $service->dropNick($nick, 'inactivity', null);
    }

    #[Test]
    public function dropNickWithManualReasonAndNullOperatorLogsToDebugWithAsteriskOperator(): void
    {
        $nick = $this->createNickWithId('TestNick', 300);

        $nickRepository = $this->createMock(RegisteredNickRepositoryInterface::class);
        $nickRepository->expects(self::once())->method('delete');

        $userLookup = $this->createStub(NetworkUserLookupPort::class);
        $userLookup->method('findByNick')->willReturn(null);

        $forceService = $this->createMock(NickForceService::class);
        $forceService->expects(self::never())->method('forceGuestNick');

        $eventDispatcher = $this->createMock(EventBusInterface::class);
        $eventDispatcher->expects(self::exactly(2))->method('dispatch');

        $debug = $this->createMock(ServiceDebugNotifierInterface::class);
        $debug->expects(self::once())->method('log')->with(
            '*',
            'DROP',
            'TestNick',
            null,
            null,
            'manual',
            self::anything(),
        );

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('info');

        $service = new NickDropService(
            $nickRepository,
            $userLookup,
            $forceService,
            $eventDispatcher,
            $debug,
            $logger,
            new IdentifiedSessionRegistry(),
            $this->immediateTransactionManager(),
            'Guest-',
        );

        $service->dropNick($nick, 'manual', null);
    }

    #[Test]
    public function softDropNickMarksPendingDeletionWithoutDispatchingDropEvent(): void
    {
        $nick = $this->createNickWithId('SoftNick', 301);

        $nickRepository = $this->createMock(RegisteredNickRepositoryInterface::class);
        $nickRepository->expects(self::once())->method('save')->with($nick);
        $nickRepository->expects(self::never())->method('delete');

        $userLookup = $this->createStub(NetworkUserLookupPort::class);
        $userLookup->method('findByNick')->willReturn(null);

        $eventDispatcher = $this->createMock(EventBusInterface::class);
        $eventDispatcher->expects(self::never())->method('dispatch');

        $debug = $this->createMock(ServiceDebugNotifierInterface::class);
        $debug->expects(self::once())->method('log')->with('OperUser', 'DROP', 'SoftNick', null, null, 'manual', self::anything());

        $sessionRegistry = new IdentifiedSessionRegistry();
        // No session registered — findUidByNick returns null, remove not called

        $service = new NickDropService(
            $nickRepository,
            $userLookup,
            $this->createStub(NickForceService::class),
            $eventDispatcher,
            $debug,
            $this->createStub(LoggerInterface::class),
            $sessionRegistry,
            $this->immediateTransactionManager(),
            'Guest-',
        );

        $service->softDropNick($nick, 'OperUser');

        self::assertTrue($nick->isPendingDeletion());
    }

    #[Test]
    public function softDropNickForcesOnlineUserToGuestNick(): void
    {
        $nick = $this->createNickWithId('SoftOnline', 303);
        $onlineUser = new SenderView('UID303', 'SoftOnline', 'i', 'h', 'c', 'ip', false, false, 'SID1', 'h', 'o');
        $nickRepository = $this->createMock(RegisteredNickRepositoryInterface::class);
        $nickRepository->expects(self::once())->method('save')->with($nick);
        $userLookup = $this->createStub(NetworkUserLookupPort::class);
        $userLookup->method('findByNick')->willReturn($onlineUser);
        $forceService = $this->createMock(NickForceService::class);
        $forceService->expects(self::once())->method('forceGuestNick')->with('UID303', null, 'nick-drop');

        $sessionRegistry = new IdentifiedSessionRegistry();
        $sessionRegistry->register('UID303', 'SoftOnline');

        $service = new NickDropService(
            $nickRepository,
            $userLookup,
            $forceService,
            $this->createStub(EventBusInterface::class),
            $this->createStub(ServiceDebugNotifierInterface::class),
            $this->createStub(LoggerInterface::class),
            $sessionRegistry,
            $this->immediateTransactionManager(),
            'Guest-',
        );

        $service->softDropNick($nick, 'OperUser');

        self::assertNull($sessionRegistry->findUidByNick('SoftOnline'));
    }

    #[Test]
    public function softDropNickRemovesSessionWhenOfflineButSessionExists(): void
    {
        $nick = $this->createNickWithId('OfflineSession', 304);

        $nickRepository = $this->createMock(RegisteredNickRepositoryInterface::class);
        $nickRepository->expects(self::once())->method('save')->with($nick);

        $userLookup = $this->createStub(NetworkUserLookupPort::class);
        $userLookup->method('findByNick')->willReturn(null);

        $sessionRegistry = new IdentifiedSessionRegistry();
        $sessionRegistry->register('UID404', 'OfflineSession');

        $service = new NickDropService(
            $nickRepository,
            $userLookup,
            $this->createStub(NickForceService::class),
            $this->createStub(EventBusInterface::class),
            $this->createStub(ServiceDebugNotifierInterface::class),
            $this->createStub(LoggerInterface::class),
            $sessionRegistry,
            $this->immediateTransactionManager(),
            'Guest-',
        );

        $service->softDropNick($nick);

        self::assertNull($sessionRegistry->findUidByNick('OfflineSession'));
    }

    #[Test]
    public function restoreNickRestoresAndSaves(): void
    {
        $nick = $this->createNickWithId('RestoreNick', 302);
        $nick->markPendingDeletion(new DateTimeImmutable('-1 day'));

        $nickRepository = $this->createMock(RegisteredNickRepositoryInterface::class);
        $nickRepository->expects(self::once())->method('save')->with($nick);

        $debug = $this->createMock(ServiceDebugNotifierInterface::class);
        $debug->expects(self::once())->method('log')->with('OperUser', 'RESTORE', 'RestoreNick', null, null, 'manual');

        $service = new NickDropService(
            $nickRepository,
            $this->createStub(NetworkUserLookupPort::class),
            $this->createStub(NickForceService::class),
            $this->createStub(EventBusInterface::class),
            $debug,
            $this->createStub(LoggerInterface::class),
            new IdentifiedSessionRegistry(),
            $this->immediateTransactionManager(),
            'Guest-',
        );

        $service->restoreNick($nick, 'OperUser');

        self::assertTrue($nick->isRegistered());
    }

    #[Test]
    public function exposesGuestPrefix(): void
    {
        $service = new NickDropService(
            $this->createStub(RegisteredNickRepositoryInterface::class),
            $this->createStub(NetworkUserLookupPort::class),
            $this->createStub(NickForceService::class),
            $this->createStub(EventBusInterface::class),
            $this->createStub(ServiceDebugNotifierInterface::class),
            $this->createStub(LoggerInterface::class),
            new IdentifiedSessionRegistry(),
            $this->immediateTransactionManager(),
            'Guest-',
        );

        self::assertSame('Guest-', $service->getGuestPrefix());
    }

    private function createNickWithId(string $nickname, int $id): RegisteredNick
    {
        $nick = RegisteredNick::createPending($nickname, 'hash', 'test@example.com', 'en', new DateTimeImmutable('+1 hour'));
        $nick->activate();

        $reflection = new ReflectionClass(RegisteredNick::class);
        $idProp = $reflection->getProperty('id');
        $idProp->setValue($nick, $id);

        return $nick;
    }

    private function immediateTransactionManager(): TransactionManagerInterface
    {
        $transactionManager = $this->createStub(TransactionManagerInterface::class);
        $transactionManager->method('transactional')->willReturnCallback(
            static fn (callable $operation): mixed => $operation(),
        );

        return $transactionManager;
    }
}
