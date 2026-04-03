<?php

declare(strict_types=1);

namespace App\Tests\Application\NickServ\Service;

use App\Application\NickServ\Command\NickServNotifierInterface;
use App\Application\NickServ\IdentifiedSessionRegistry;
use App\Application\NickServ\PendingNickRestoreRegistryInterface;
use App\Application\NickServ\Service\NickForceService;
use App\Application\Port\NetworkUserLookupPort;
use App\Application\Port\SenderView;
use App\Domain\NickServ\Entity\RegisteredNick;
use App\Domain\NickServ\Event\UserDeidentifiedEvent;
use App\Domain\NickServ\Repository\RegisteredNickRepositoryInterface;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

use function str_starts_with;
use function strlen;

#[CoversClass(NickForceService::class)]
final class NickForceServiceTest extends TestCase
{
    #[Test]
    public function forceGuestNickWithNullGuestNickGeneratesIt(): void
    {
        $identifiedRegistry = new IdentifiedSessionRegistry();
        $notifier = $this->createMock(NickServNotifierInterface::class);
        $pendingRegistry = $this->createMock(PendingNickRestoreRegistryInterface::class);
        $userLookup = $this->createMock(NetworkUserLookupPort::class);

        $user = $this->createOnlineUser();

        $userLookup->expects(self::once())->method('findByUid')->with('UID123')->willReturn($user);
        $notifier->expects(self::once())->method('setUserAccount')->with('UID123', '0');
        $notifier->expects(self::once())->method('setUserVhost')->with('UID123', '', 'SID1');
        $notifier->expects(self::once())->method('forceNick')->with('UID123', self::callback(static fn (string $nick): bool => str_starts_with($nick, 'Guest-') && strlen($nick) > 6));
        $pendingRegistry->expects(self::once())->method('mark')->with('UID123');

        $service = new NickForceService(
            $identifiedRegistry,
            $notifier,
            $pendingRegistry,
            $userLookup,
            $this->createStub(RegisteredNickRepositoryInterface::class),
            $this->createStub(EventDispatcherInterface::class),
            $this->createStub(LoggerInterface::class),
            'Guest-',
        );

        $service->forceGuestNick('UID123');
    }

    #[Test]
    public function forceGuestNickWithProvidedGuestNickUsesIt(): void
    {
        $identifiedRegistry = new IdentifiedSessionRegistry();
        $notifier = $this->createMock(NickServNotifierInterface::class);
        $pendingRegistry = $this->createStub(PendingNickRestoreRegistryInterface::class);
        $userLookup = $this->createMock(NetworkUserLookupPort::class);

        $user = $this->createOnlineUser();

        $userLookup->expects(self::once())->method('findByUid')->with('UID123')->willReturn($user);
        $notifier->expects(self::once())->method('forceNick')->with('UID123', 'CustomPrefix-ABC123');

        $service = new NickForceService(
            $identifiedRegistry,
            $notifier,
            $pendingRegistry,
            $userLookup,
            $this->createStub(RegisteredNickRepositoryInterface::class),
            $this->createStub(EventDispatcherInterface::class),
            $this->createStub(LoggerInterface::class),
            'Guest-',
        );

        $service->forceGuestNick('UID123', 'CustomPrefix-ABC123');
    }

    #[Test]
    public function forceGuestNickWithOfflineUserReturnsEarly(): void
    {
        $identifiedRegistry = new IdentifiedSessionRegistry();
        $notifier = $this->createMock(NickServNotifierInterface::class);
        $pendingRegistry = $this->createMock(PendingNickRestoreRegistryInterface::class);
        $userLookup = $this->createMock(NetworkUserLookupPort::class);
        $logger = $this->createMock(LoggerInterface::class);

        $userLookup->expects(self::once())->method('findByUid')->with('UID123')->willReturn(null);
        $notifier->expects(self::never())->method('setUserAccount');
        $notifier->expects(self::never())->method('forceNick');
        $pendingRegistry->expects(self::never())->method('mark');
        $logger->expects(self::once())->method('warning');

        $service = new NickForceService(
            $identifiedRegistry,
            $notifier,
            $pendingRegistry,
            $userLookup,
            $this->createStub(RegisteredNickRepositoryInterface::class),
            $this->createStub(EventDispatcherInterface::class),
            $logger,
            'Guest-',
        );

        $service->forceGuestNick('UID123');
    }

    #[Test]
    public function forceGuestNickWithIdentifiedUserDispatchesEvent(): void
    {
        $identifiedRegistry = new IdentifiedSessionRegistry();
        $notifier = $this->createStub(NickServNotifierInterface::class);
        $pendingRegistry = $this->createStub(PendingNickRestoreRegistryInterface::class);
        $userLookup = $this->createMock(NetworkUserLookupPort::class);
        $nickRepository = $this->createMock(RegisteredNickRepositoryInterface::class);
        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);

        $user = $this->createOnlineUser();
        $account = $this->createNickWithId('TestNick', 42);

        $identifiedRegistry->register('UID123', 'TestNick');

        $userLookup->expects(self::once())->method('findByUid')->with('UID123')->willReturn($user);
        $nickRepository->expects(self::once())->method('findByNick')->with('TestNick')->willReturn($account);
        $eventDispatcher->expects(self::once())->method('dispatch')->with(self::callback(
            static fn (UserDeidentifiedEvent $event): bool => 'UID123' === $event->uid && 42 === $event->nickId && 'TestNick' === $event->nickname,
        ));

        $service = new NickForceService(
            $identifiedRegistry,
            $notifier,
            $pendingRegistry,
            $userLookup,
            $nickRepository,
            $eventDispatcher,
            $this->createStub(LoggerInterface::class),
            'Guest-',
        );

        $service->forceGuestNick('UID123');

        self::assertNull($identifiedRegistry->findNick('UID123'));
    }

    #[Test]
    public function forceGuestNickWithIdentifiedUserRemovesSession(): void
    {
        $identifiedRegistry = new IdentifiedSessionRegistry();
        $notifier = $this->createStub(NickServNotifierInterface::class);
        $pendingRegistry = $this->createStub(PendingNickRestoreRegistryInterface::class);
        $userLookup = $this->createMock(NetworkUserLookupPort::class);

        $user = $this->createOnlineUser();

        $identifiedRegistry->register('UID123', 'TestNick');
        $userLookup->expects(self::once())->method('findByUid')->willReturn($user);

        $service = new NickForceService(
            $identifiedRegistry,
            $notifier,
            $pendingRegistry,
            $userLookup,
            $this->createStub(RegisteredNickRepositoryInterface::class),
            $this->createStub(EventDispatcherInterface::class),
            $this->createStub(LoggerInterface::class),
            'Guest-',
        );

        $service->forceGuestNick('UID123');

        self::assertNull($identifiedRegistry->findNick('UID123'));
    }

    #[Test]
    public function forceGuestNickClearsAccountAndVhost(): void
    {
        $identifiedRegistry = new IdentifiedSessionRegistry();
        $notifier = $this->createMock(NickServNotifierInterface::class);
        $pendingRegistry = $this->createStub(PendingNickRestoreRegistryInterface::class);
        $userLookup = $this->createMock(NetworkUserLookupPort::class);

        $user = $this->createOnlineUser();

        $userLookup->expects(self::once())->method('findByUid')->willReturn($user);
        $notifier->expects(self::once())->method('setUserAccount')->with('UID123', '0');
        $notifier->expects(self::once())->method('setUserVhost')->with('UID123', '', 'SID1');

        $service = new NickForceService(
            $identifiedRegistry,
            $notifier,
            $pendingRegistry,
            $userLookup,
            $this->createStub(RegisteredNickRepositoryInterface::class),
            $this->createStub(EventDispatcherInterface::class),
            $this->createStub(LoggerInterface::class),
            'Guest-',
        );

        $service->forceGuestNick('UID123');
    }

    #[Test]
    public function forceGuestNickMarksPendingAndForceNick(): void
    {
        $identifiedRegistry = new IdentifiedSessionRegistry();
        $notifier = $this->createMock(NickServNotifierInterface::class);
        $pendingRegistry = $this->createMock(PendingNickRestoreRegistryInterface::class);
        $userLookup = $this->createMock(NetworkUserLookupPort::class);

        $user = $this->createOnlineUser();

        $userLookup->expects(self::once())->method('findByUid')->willReturn($user);
        $pendingRegistry->expects(self::once())->method('mark')->with('UID123');
        $notifier->expects(self::once())->method('forceNick');

        $service = new NickForceService(
            $identifiedRegistry,
            $notifier,
            $pendingRegistry,
            $userLookup,
            $this->createStub(RegisteredNickRepositoryInterface::class),
            $this->createStub(EventDispatcherInterface::class),
            $this->createStub(LoggerInterface::class),
            'Guest-',
        );

        $service->forceGuestNick('UID123');
    }

    #[Test]
    public function forceGuestNickLogsWithReason(): void
    {
        $identifiedRegistry = new IdentifiedSessionRegistry();
        $notifier = $this->createStub(NickServNotifierInterface::class);
        $pendingRegistry = $this->createStub(PendingNickRestoreRegistryInterface::class);
        $userLookup = $this->createMock(NetworkUserLookupPort::class);
        $logger = $this->createMock(LoggerInterface::class);

        $user = $this->createOnlineUser();

        $userLookup->expects(self::once())->method('findByUid')->willReturn($user);
        $logger->expects(self::once())->method('info')->with(self::stringContains('reason: suspension'));

        $service = new NickForceService(
            $identifiedRegistry,
            $notifier,
            $pendingRegistry,
            $userLookup,
            $this->createStub(RegisteredNickRepositoryInterface::class),
            $this->createStub(EventDispatcherInterface::class),
            $logger,
            'Guest-',
        );

        $service->forceGuestNick('UID123', null, 'suspension');
    }

    #[Test]
    public function forceGuestNickWithCustomPrefixGeneratesCorrectNick(): void
    {
        $identifiedRegistry = new IdentifiedSessionRegistry();
        $notifier = $this->createMock(NickServNotifierInterface::class);
        $pendingRegistry = $this->createStub(PendingNickRestoreRegistryInterface::class);
        $userLookup = $this->createMock(NetworkUserLookupPort::class);

        $user = $this->createOnlineUser();

        $userLookup->expects(self::once())->method('findByUid')->willReturn($user);
        $notifier->expects(self::once())->method('forceNick')->with('UID123', self::callback(
            static fn (string $nick): bool => str_starts_with($nick, 'Renamed-') && strlen($nick) > 8,
        ));

        $service = new NickForceService(
            $identifiedRegistry,
            $notifier,
            $pendingRegistry,
            $userLookup,
            $this->createStub(RegisteredNickRepositoryInterface::class),
            $this->createStub(EventDispatcherInterface::class),
            $this->createStub(LoggerInterface::class),
            'Renamed-',
        );

        $service->forceGuestNick('UID123');
    }

    private function createOnlineUser(): SenderView
    {
        return new SenderView(
            uid: 'UID123',
            nick: 'TestUser',
            ident: 'testuser',
            hostname: 'example.com',
            cloakedHost: 'clk.example.com',
            ipBase64: 'aBsDeF==',
            isIdentified: true,
            isOper: false,
            serverSid: 'SID1',
            displayHost: 'clk.example.com',
            modes: 'iwx',
        );
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
