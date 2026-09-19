<?php

declare(strict_types=1);

namespace App\Tests\NickServ\Application\Service;

use App\NickServ\Adapter\Out\InMemory\IdentifiedSessionRegistry;
use App\NickServ\Application\Model\NetworkUser;
use App\NickServ\Application\Port\Out\GuestNicknameGenerator;
use App\NickServ\Application\Port\Out\NickNetworkActions;
use App\NickServ\Application\Port\Out\NickNetworkUserLookup;
use App\NickServ\Application\Port\Out\NickServActivitySink;
use App\NickServ\Application\Port\Out\NickServEventPublisher;
use App\NickServ\Application\Port\Out\PendingNickRestoreRegistryInterface;
use App\NickServ\Application\Port\Out\RegisteredNickRepositoryInterface;
use App\NickServ\Application\PublishedEvent\UserDeidentifiedEvent;
use App\NickServ\Application\Service\NickForceService;
use App\NickServ\Domain\Entity\RegisteredNick;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

use function str_starts_with;
use function strlen;

#[CoversClass(NickForceService::class)]
final class NickForceServiceTest extends TestCase
{
    #[Test]
    public function forceGuestNickWithNullGuestNickGeneratesIt(): void
    {
        $identifiedRegistry = new IdentifiedSessionRegistry();
        $notifier = $this->createMock(NickNetworkActions::class);
        $pendingRegistry = $this->createMock(PendingNickRestoreRegistryInterface::class);
        $userLookup = $this->createMock(NickNetworkUserLookup::class);

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
            $this->createStub(NickServEventPublisher::class),
            $this->createStub(NickServActivitySink::class),
            $this->guestNicknameGenerator(),
            'Guest-',
        );

        $nick = $service->forceGuestNick('UID123');

        self::assertSame('Guest-ABC1234', $nick);
    }

    #[Test]
    public function forceGuestNickWithProvidedGuestNickUsesIt(): void
    {
        $identifiedRegistry = new IdentifiedSessionRegistry();
        $notifier = $this->createMock(NickNetworkActions::class);
        $pendingRegistry = $this->createStub(PendingNickRestoreRegistryInterface::class);
        $userLookup = $this->createMock(NickNetworkUserLookup::class);

        $user = $this->createOnlineUser();

        $userLookup->expects(self::once())->method('findByUid')->with('UID123')->willReturn($user);
        $notifier->expects(self::once())->method('forceNick')->with('UID123', 'CustomPrefix-ABC123');

        $service = new NickForceService(
            $identifiedRegistry,
            $notifier,
            $pendingRegistry,
            $userLookup,
            $this->createStub(RegisteredNickRepositoryInterface::class),
            $this->createStub(NickServEventPublisher::class),
            $this->createStub(NickServActivitySink::class),
            $this->guestNicknameGenerator(),
            'Guest-',
        );

        $nick = $service->forceGuestNick('UID123', 'CustomPrefix-ABC123');

        self::assertSame('CustomPrefix-ABC123', $nick);
    }

    #[Test]
    public function forceGuestNickWithOfflineUserReturnsEarly(): void
    {
        $identifiedRegistry = new IdentifiedSessionRegistry();
        $notifier = $this->createMock(NickNetworkActions::class);
        $pendingRegistry = $this->createMock(PendingNickRestoreRegistryInterface::class);
        $userLookup = $this->createMock(NickNetworkUserLookup::class);
        $logger = $this->createMock(NickServActivitySink::class);

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
            $this->createStub(NickServEventPublisher::class),
            $logger,
            $this->guestNicknameGenerator(),
            'Guest-',
        );

        $nick = $service->forceGuestNick('UID123');

        self::assertNull($nick);
    }

    #[Test]
    public function forceGuestNickWithIdentifiedUserDispatchesEvent(): void
    {
        $identifiedRegistry = new IdentifiedSessionRegistry();
        $notifier = $this->createStub(NickNetworkActions::class);
        $pendingRegistry = $this->createStub(PendingNickRestoreRegistryInterface::class);
        $userLookup = $this->createMock(NickNetworkUserLookup::class);
        $nickRepository = $this->createMock(RegisteredNickRepositoryInterface::class);
        $eventDispatcher = $this->createMock(NickServEventPublisher::class);

        $user = $this->createOnlineUser();
        $account = $this->createNickWithId('TestNick', 42);

        $identifiedRegistry->register('UID123', 'TestNick');

        $userLookup->expects(self::once())->method('findByUid')->with('UID123')->willReturn($user);
        $nickRepository->expects(self::once())->method('findByNick')->with('TestNick')->willReturn($account);
        $eventDispatcher->expects(self::once())->method('publish')->with(self::callback(
            static fn (UserDeidentifiedEvent $event): bool => 'UID123' === $event->uid && 42 === $event->nickId && 'TestNick' === $event->nickname,
        ));

        $service = new NickForceService(
            $identifiedRegistry,
            $notifier,
            $pendingRegistry,
            $userLookup,
            $nickRepository,
            $eventDispatcher,
            $this->createStub(NickServActivitySink::class),
            $this->guestNicknameGenerator(),
            'Guest-',
        );

        $nick = $service->forceGuestNick('UID123');

        self::assertSame('Guest-ABC1234', $nick);
        self::assertNull($identifiedRegistry->findNick('UID123'));
    }

    #[Test]
    public function forceGuestNickWithIdentifiedUserRemovesSession(): void
    {
        $identifiedRegistry = new IdentifiedSessionRegistry();
        $notifier = $this->createStub(NickNetworkActions::class);
        $pendingRegistry = $this->createStub(PendingNickRestoreRegistryInterface::class);
        $userLookup = $this->createMock(NickNetworkUserLookup::class);

        $user = $this->createOnlineUser();

        $identifiedRegistry->register('UID123', 'TestNick');
        $userLookup->expects(self::once())->method('findByUid')->willReturn($user);

        $service = new NickForceService(
            $identifiedRegistry,
            $notifier,
            $pendingRegistry,
            $userLookup,
            $this->createStub(RegisteredNickRepositoryInterface::class),
            $this->createStub(NickServEventPublisher::class),
            $this->createStub(NickServActivitySink::class),
            $this->guestNicknameGenerator(),
            'Guest-',
        );

        $nick = $service->forceGuestNick('UID123');

        self::assertSame('Guest-ABC1234', $nick);
        self::assertNull($identifiedRegistry->findNick('UID123'));
    }

    #[Test]
    public function forceGuestNickClearsAccountAndVhost(): void
    {
        $identifiedRegistry = new IdentifiedSessionRegistry();
        $notifier = $this->createMock(NickNetworkActions::class);
        $pendingRegistry = $this->createStub(PendingNickRestoreRegistryInterface::class);
        $userLookup = $this->createMock(NickNetworkUserLookup::class);

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
            $this->createStub(NickServEventPublisher::class),
            $this->createStub(NickServActivitySink::class),
            $this->guestNicknameGenerator(),
            'Guest-',
        );

        $nick = $service->forceGuestNick('UID123');

        self::assertSame('Guest-ABC1234', $nick);
    }

    #[Test]
    public function forceGuestNickMarksPendingAndForceNick(): void
    {
        $identifiedRegistry = new IdentifiedSessionRegistry();
        $notifier = $this->createMock(NickNetworkActions::class);
        $pendingRegistry = $this->createMock(PendingNickRestoreRegistryInterface::class);
        $userLookup = $this->createMock(NickNetworkUserLookup::class);

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
            $this->createStub(NickServEventPublisher::class),
            $this->createStub(NickServActivitySink::class),
            $this->guestNicknameGenerator(),
            'Guest-',
        );

        $nick = $service->forceGuestNick('UID123');

        self::assertSame('Guest-ABC1234', $nick);
    }

    #[Test]
    public function forceGuestNickLogsWithReason(): void
    {
        $identifiedRegistry = new IdentifiedSessionRegistry();
        $notifier = $this->createStub(NickNetworkActions::class);
        $pendingRegistry = $this->createStub(PendingNickRestoreRegistryInterface::class);
        $userLookup = $this->createMock(NickNetworkUserLookup::class);
        $logger = $this->createMock(NickServActivitySink::class);

        $user = $this->createOnlineUser();

        $userLookup->expects(self::once())->method('findByUid')->willReturn($user);
        $logger->expects(self::once())->method('info')->with(self::stringContains('reason: suspension'));

        $service = new NickForceService(
            $identifiedRegistry,
            $notifier,
            $pendingRegistry,
            $userLookup,
            $this->createStub(RegisteredNickRepositoryInterface::class),
            $this->createStub(NickServEventPublisher::class),
            $logger,
            $this->guestNicknameGenerator(),
            'Guest-',
        );

        $nick = $service->forceGuestNick('UID123', null, 'suspension');

        self::assertSame('Guest-ABC1234', $nick);
    }

    #[Test]
    public function forceGuestNickWithCustomPrefixGeneratesCorrectNick(): void
    {
        $identifiedRegistry = new IdentifiedSessionRegistry();
        $notifier = $this->createMock(NickNetworkActions::class);
        $pendingRegistry = $this->createStub(PendingNickRestoreRegistryInterface::class);
        $userLookup = $this->createMock(NickNetworkUserLookup::class);

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
            $this->createStub(NickServEventPublisher::class),
            $this->createStub(NickServActivitySink::class),
            $this->guestNicknameGenerator(),
            'Renamed-',
        );

        $nick = $service->forceGuestNick('UID123');

        self::assertSame('Renamed-ABC1234', $nick);
    }

    private function createOnlineUser(): NetworkUser
    {
        return new NetworkUser(
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
        $nick = RegisteredNick::createPending($nickname, 'hash', 'test@example.com', 'en', new DateTimeImmutable('+1 hour'), new DateTimeImmutable());
        $nick->activate();

        $reflection = new ReflectionClass(RegisteredNick::class);
        $idProp = $reflection->getProperty('id');
        $idProp->setValue($nick, $id);

        return $nick;
    }

    private function guestNicknameGenerator(): GuestNicknameGenerator
    {
        $generator = $this->createStub(GuestNicknameGenerator::class);
        $generator->method('generate')->willReturnCallback(
            static fn (string $prefix): string => $prefix . 'ABC1234',
        );

        return $generator;
    }
}
