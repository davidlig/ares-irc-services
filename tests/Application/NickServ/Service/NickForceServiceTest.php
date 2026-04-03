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
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

use function strlen;

#[CoversClass(NickForceService::class)]
final class NickForceServiceTest extends TestCase
{
    private IdentifiedSessionRegistry $identifiedRegistry;

    private NickServNotifierInterface&MockObject $notifier;

    private PendingNickRestoreRegistryInterface&MockObject $pendingRegistry;

    private NetworkUserLookupPort&MockObject $userLookup;

    private RegisteredNickRepositoryInterface&MockObject $nickRepository;

    private EventDispatcherInterface&MockObject $eventDispatcher;

    private LoggerInterface&MockObject $logger;

    private NickForceService $service;

    protected function setUp(): void
    {
        $this->identifiedRegistry = new IdentifiedSessionRegistry();
        $this->notifier = $this->createMock(NickServNotifierInterface::class);
        $this->pendingRegistry = $this->createMock(PendingNickRestoreRegistryInterface::class);
        $this->userLookup = $this->createMock(NetworkUserLookupPort::class);
        $this->nickRepository = $this->createMock(RegisteredNickRepositoryInterface::class);
        $this->eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->service = new NickForceService(
            $this->identifiedRegistry,
            $this->notifier,
            $this->pendingRegistry,
            $this->userLookup,
            $this->nickRepository,
            $this->eventDispatcher,
            $this->logger,
            'Guest-',
        );
    }

    #[Test]
    public function forceGuestNickWithNullGuestNickGeneratesIt(): void
    {
        $user = $this->createOnlineUser();

        $this->userLookup->method('findByUid')->with('UID123')->willReturn($user);

        $this->notifier->expects(self::once())->method('setUserAccount')->with('UID123', '0');
        $this->notifier->expects(self::once())->method('setUserVhost')->with('UID123', '', 'SID1');
        $this->notifier->expects(self::once())->method('forceNick')->with('UID123', self::callback(static fn (string $nick): bool => str_starts_with($nick, 'Guest-') && strlen($nick) > 6));
        $this->pendingRegistry->expects(self::once())->method('mark')->with('UID123');
        $this->logger->expects(self::once())->method('info');

        $this->service->forceGuestNick('UID123');
    }

    #[Test]
    public function forceGuestNickWithProvidedGuestNickUsesIt(): void
    {
        $user = $this->createOnlineUser();

        $this->userLookup->method('findByUid')->with('UID123')->willReturn($user);

        $this->notifier->expects(self::once())->method('forceNick')->with('UID123', 'CustomPrefix-ABC123');

        $this->service->forceGuestNick('UID123', 'CustomPrefix-ABC123');
    }

    #[Test]
    public function forceGuestNickWithOfflineUserReturnsEarly(): void
    {
        $this->userLookup->method('findByUid')->with('UID123')->willReturn(null);

        $this->notifier->expects(self::never())->method('setUserAccount');
        $this->notifier->expects(self::never())->method('forceNick');
        $this->pendingRegistry->expects(self::never())->method('mark');

        $this->logger->expects(self::once())->method('warning');

        $this->service->forceGuestNick('UID123');
    }

    #[Test]
    public function forceGuestNickWithIdentifiedUserDispatchesEvent(): void
    {
        $user = $this->createOnlineUser();
        $account = $this->createNickWithId('TestNick', 42);

        $this->identifiedRegistry->register('UID123', 'TestNick');

        $this->userLookup->method('findByUid')->with('UID123')->willReturn($user);
        $this->nickRepository->method('findByNick')->with('TestNick')->willReturn($account);

        $this->eventDispatcher->expects(self::once())->method('dispatch')->with(self::callback(
            static fn (UserDeidentifiedEvent $event): bool => 'UID123' === $event->uid && 42 === $event->nickId && 'TestNick' === $event->nickname,
        ));

        $this->service->forceGuestNick('UID123');

        self::assertNull($this->identifiedRegistry->findNick('UID123'));
    }

    #[Test]
    public function forceGuestNickWithIdentifiedUserRemovesSession(): void
    {
        $user = $this->createOnlineUser();

        $this->identifiedRegistry->register('UID123', 'TestNick');
        $this->userLookup->method('findByUid')->willReturn($user);
        $this->nickRepository->method('findByNick')->willReturn(null);

        $this->service->forceGuestNick('UID123');

        self::assertNull($this->identifiedRegistry->findNick('UID123'));
    }

    #[Test]
    public function forceGuestNickClearsAccountAndVhost(): void
    {
        $user = $this->createOnlineUser();

        $this->userLookup->method('findByUid')->willReturn($user);

        $this->notifier->expects(self::once())->method('setUserAccount')->with('UID123', '0');
        $this->notifier->expects(self::once())->method('setUserVhost')->with('UID123', '', 'SID1');

        $this->service->forceGuestNick('UID123');
    }

    #[Test]
    public function forceGuestNickMarksPendingAndForceNick(): void
    {
        $user = $this->createOnlineUser();

        $this->userLookup->method('findByUid')->willReturn($user);

        $this->pendingRegistry->expects(self::once())->method('mark')->with('UID123');
        $this->notifier->expects(self::once())->method('forceNick');

        $this->service->forceGuestNick('UID123');
    }

    #[Test]
    public function forceGuestNickLogsWithReason(): void
    {
        $user = $this->createOnlineUser();

        $this->userLookup->method('findByUid')->willReturn($user);

        $this->logger->expects(self::once())->method('info')->with(self::stringContains('reason: suspension'));

        $this->service->forceGuestNick('UID123', null, 'suspension');
    }

    #[Test]
    public function forceGuestNickWithCustomPrefixGeneratesCorrectNick(): void
    {
        $service = new NickForceService(
            $this->identifiedRegistry,
            $this->notifier,
            $this->pendingRegistry,
            $this->userLookup,
            $this->nickRepository,
            $this->eventDispatcher,
            $this->logger,
            'Renamed-',
        );

        $user = $this->createOnlineUser();

        $this->userLookup->method('findByUid')->willReturn($user);

        $this->notifier->expects(self::once())->method('forceNick')->with('UID123', self::callback(
            static fn (string $nick): bool => str_starts_with($nick, 'Renamed-') && strlen($nick) > 8,
        ));

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
