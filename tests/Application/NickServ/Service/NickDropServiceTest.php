<?php

declare(strict_types=1);

namespace App\Tests\Application\NickServ\Service;

use App\Application\NickServ\Service\NickDropService;
use App\Application\NickServ\Service\NickForceService;
use App\Application\Port\DebugActionPort;
use App\Application\Port\NetworkUserLookupPort;
use App\Application\Port\SenderView;
use App\Domain\NickServ\Entity\RegisteredNick;
use App\Domain\NickServ\Event\NickDropEvent;
use App\Domain\NickServ\Repository\RegisteredNickRepositoryInterface;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

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

        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->expects(self::once())->method('dispatch')->with(self::callback(static fn (NickDropEvent $event): bool => 42 === $event->nickId
                && 'TestNick' === $event->nickname
                && 'manual' === $event->reason));

        $debug = $this->createMock(DebugActionPort::class);
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

        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->expects(self::once())->method('dispatch');

        $debug = $this->createMock(DebugActionPort::class);
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
            'Guest-',
        );

        $service->dropNick($nick, 'manual', 'OperUser');
    }

    #[Test]
    public function dropNickWithInactivityReasonDoesNotLogToDebug(): void
    {
        $nick = $this->createNickWithId('InactiveNick', 200);

        $nickRepository = $this->createMock(RegisteredNickRepositoryInterface::class);
        $nickRepository->expects(self::once())->method('delete');

        $userLookup = $this->createStub(NetworkUserLookupPort::class);
        $userLookup->method('findByNick')->willReturn(null);

        $forceService = $this->createMock(NickForceService::class);
        $forceService->expects(self::never())->method('forceGuestNick');

        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->expects(self::once())->method('dispatch')->with(self::callback(static fn (NickDropEvent $event): bool => 'inactivity' === $event->reason));

        $debug = $this->createMock(DebugActionPort::class);
        $debug->expects(self::never())->method('log');

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('info');

        $service = new NickDropService(
            $nickRepository,
            $userLookup,
            $forceService,
            $eventDispatcher,
            $debug,
            $logger,
            'Guest-',
        );

        $service->dropNick($nick, 'inactivity', null);
    }

    #[Test]
    public function dropNickWithManualReasonAndNullOperatorDoesNotLogToDebug(): void
    {
        $nick = $this->createNickWithId('TestNick', 300);

        $nickRepository = $this->createMock(RegisteredNickRepositoryInterface::class);
        $nickRepository->expects(self::once())->method('delete');

        $userLookup = $this->createStub(NetworkUserLookupPort::class);
        $userLookup->method('findByNick')->willReturn(null);

        $forceService = $this->createMock(NickForceService::class);
        $forceService->expects(self::never())->method('forceGuestNick');

        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->expects(self::once())->method('dispatch');

        $debug = $this->createMock(DebugActionPort::class);
        $debug->expects(self::never())->method('log');

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('info');

        $service = new NickDropService(
            $nickRepository,
            $userLookup,
            $forceService,
            $eventDispatcher,
            $debug,
            $logger,
            'Guest-',
        );

        $service->dropNick($nick, 'manual', null);
    }

    private function createNickWithId(string $nickname, int $id): RegisteredNick
    {
        $nick = RegisteredNick::createPending($nickname, 'hash', 'test@example.com', 'en', new DateTimeImmutable('+1 hour'));
        $nick->activate();

        $reflection = new ReflectionClass(RegisteredNick::class);
        $idProp = $reflection->getProperty('id');
        $idProp->setAccessible(true);
        $idProp->setValue($nick, $id);

        return $nick;
    }
}
