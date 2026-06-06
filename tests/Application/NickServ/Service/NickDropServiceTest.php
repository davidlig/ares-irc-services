<?php

declare(strict_types=1);

namespace App\Tests\Application\NickServ\Service;

use App\Application\NickServ\IdentifiedSessionRegistry;
use App\Application\NickServ\Service\NickDropService;
use App\Application\NickServ\Service\NickForceService;
use App\Application\Port\EventBusInterface;
use App\Application\Port\NetworkUserLookupPort;
use App\Application\Port\SenderView;
use App\Application\Port\ServiceDebugNotifierInterface;
use App\Domain\NickServ\Entity\RegisteredNick;
use App\Domain\NickServ\Event\NickDropEvent;
use App\Domain\NickServ\Repository\RegisteredNickRepositoryInterface;
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

        $eventDispatcher = $this->createMock(EventBusInterface::class);
        $eventDispatcher->expects(self::once())->method('dispatch')->with(self::callback(static fn (NickDropEvent $event): bool => 42 === $event->nickId
                && 'TestNick' === $event->nickname
                && 'manual' === $event->reason));

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
            'Guest-',
        );

        $service->dropNick($nick, 'manual', 'OperUser');
    }

    #[Test]
    public function dropNickWithOnlineUserForcesRenameThenDrops(): void
    {
        $nick = $this->createNickWithId('OnlineNick', 100);

        $onlineUser = new SenderView('UID123', 'OnlineNick', 'i', 'h', 'c', 'ip', false, false, 'SID1', 'h', 'o', '');

        $nickRepository = $this->createMock(RegisteredNickRepositoryInterface::class);
        $nickRepository->expects(self::once())->method('delete')->with($nick);

        $userLookup = $this->createStub(NetworkUserLookupPort::class);
        $userLookup->method('findByNick')->willReturn($onlineUser);

        $forceService = $this->createMock(NickForceService::class);
        $forceService->expects(self::once())->method('forceGuestNick')->with('UID123', null, 'nick-drop');

        $eventDispatcher = $this->createMock(EventBusInterface::class);
        $eventDispatcher->expects(self::once())->method('dispatch');

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
        $eventDispatcher->expects(self::once())->method('dispatch')->with(self::callback(static fn (NickDropEvent $event): bool => 'inactivity' === $event->reason));

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
        $eventDispatcher->expects(self::once())->method('dispatch');

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
            'Guest-',
        );

        $service->softDropNick($nick, 'OperUser');

        self::assertTrue($nick->isPendingDeletion());
    }

    #[Test]
    public function softDropNickForcesOnlineUserToGuestNick(): void
    {
        $nick = $this->createNickWithId('SoftOnline', 303);
        $onlineUser = new SenderView('UID303', 'SoftOnline', 'i', 'h', 'c', 'ip', false, false, 'SID1', 'h', 'o', '');
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
            'Guest-',
        );

        $service->restoreNick($nick, 'OperUser');

        self::assertTrue($nick->isRegistered());
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
}
