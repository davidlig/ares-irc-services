<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\NickServ\Subscriber;

use App\Application\Event\UserJoinedNetworkAppEvent;
use App\Application\NickServ\BurstState;
use App\Application\NickServ\Command\NickServNotifierInterface;
use App\Application\NickServ\IdentifiedSessionRegistry;
use App\Application\NickServ\IdentifiedUserVhostSyncService;
use App\Application\NickServ\NickProtectionService;
use App\Application\NickServ\PendingNickProtectionRegistryInterface;
use App\Application\NickServ\PendingNickRestoreRegistryInterface;
use App\Application\NickServ\Service\ForbiddenNickService;
use App\Application\NickServ\SessionLanguageRegistry;
use App\Application\NickServ\VhostDisplayResolver;
use App\Application\Port\ActiveConnectionHolderInterface;
use App\Application\Port\EventBusInterface;
use App\Application\Port\NetworkUserLookupPort;
use App\Application\Port\NickChangePreservesIdentificationInterface;
use App\Application\Port\ProtocolModuleInterface;
use App\Application\Port\SenderView;
use App\Application\Port\TranslationInterface;
use App\Application\Port\UserJoinedNetworkDTO;
use App\Domain\IRC\Connection\ConnectionInterface;
use App\Domain\IRC\Event\IrcMessageProcessedEvent;
use App\Domain\IRC\Event\NetworkBurstCompleteEvent;
use App\Domain\IRC\Event\UserModeChangedEvent;
use App\Domain\IRC\Event\UserNickChangedEvent;
use App\Domain\IRC\Event\UserQuitNetworkEvent;
use App\Domain\IRC\ValueObject\Nick;
use App\Domain\IRC\ValueObject\Uid;
use App\Domain\NickServ\Entity\RegisteredNick;
use App\Domain\NickServ\Repository\RegisteredNickRepositoryInterface;
use App\Domain\OperServ\Repository\OperIrcopRepositoryInterface;
use App\Infrastructure\NickServ\Subscriber\NickProtectionSubscriber;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[CoversClass(NickProtectionSubscriber::class)]
final class NickProtectionSubscriberTest extends TestCase
{
    private BurstState $burstState;

    private MockObject&NetworkUserLookupPort $networkUserLookup;

    private MockObject&NickServNotifierInterface $notifier;

    private NickProtectionSubscriber $subscriber;

    protected function setUp(): void
    {
        $this->burstState = new BurstState();
        $this->networkUserLookup = $this->createMock(NetworkUserLookupPort::class);
        $this->notifier = $this->createMock(NickServNotifierInterface::class);

        $nickRepository = $this->createStub(RegisteredNickRepositoryInterface::class);
        $userLookup = $this->createStub(NetworkUserLookupPort::class);
        $translator = $this->createStub(TranslationInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);
        $pendingRegistry = $this->createStub(PendingNickRestoreRegistryInterface::class);

        $nickProtectionService = new NickProtectionService(
            $nickRepository,
            $userLookup,
            $this->notifier,
            $this->burstState,
            new IdentifiedSessionRegistry(),
            new SessionLanguageRegistry(),
            $pendingRegistry,
            $translator,
            $this->createStub(EventBusInterface::class),
            $this->createStub(ForbiddenNickService::class),
            $this->createStub(ActiveConnectionHolderInterface::class),
        );

        $ircopRepository = $this->createStub(OperIrcopRepositoryInterface::class);

        $vhostSync = new IdentifiedUserVhostSyncService(
            $nickRepository,
            $this->notifier,
            new VhostDisplayResolver(),
            $ircopRepository,
            $this->createStub(ActiveConnectionHolderInterface::class),
        );

        $this->subscriber = new NickProtectionSubscriber(
            $nickProtectionService,
            $vhostSync,
            $this->burstState,
            $this->networkUserLookup,
            $this->createStub(ActiveConnectionHolderInterface::class),
        );
    }

    #[Test]
    public function getSubscribedEventsReturnsAllHandlersWithPriorities(): void
    {
        $this->networkUserLookup->expects(self::never())->method('findByUid');
        $this->notifier->expects(self::never())->method('setUserVhost');
        $events = NickProtectionSubscriber::getSubscribedEvents();

        self::assertSame(
            [
                UserJoinedNetworkAppEvent::class => ['onUserJoined', 0],
                UserQuitNetworkEvent::class => ['onUserQuit', 0],
                UserNickChangedEvent::class => ['onNickChanged', 0],
                UserModeChangedEvent::class => ['onUserModeChanged', 0],
                NetworkBurstCompleteEvent::class => ['onBurstComplete', -256],
                IrcMessageProcessedEvent::class => ['onIrcMessageProcessed', -200],
            ],
            $events,
        );
    }

    #[Test]
    public function onUserJoinedReturnsEarlyWhenSenderViewNotFound(): void
    {
        $dto = $this->createUserJoinedDTO('001ABC');
        $event = new UserJoinedNetworkAppEvent($dto);

        $this->networkUserLookup
            ->expects(self::once())
            ->method('findByUid')
            ->with('001ABC')
            ->willReturn(null);

        $this->notifier->expects(self::never())->method('setUserVhost');

        $this->subscriber->onUserJoined($event);
    }

    #[Test]
    public function onUserJoinedAddsPendingWhenBurstNotComplete(): void
    {
        $dto = $this->createUserJoinedDTO('001ABC');
        $event = new UserJoinedNetworkAppEvent($dto);
        $senderView = new SenderView(
            uid: '001ABC',
            nick: 'Test',
            ident: 'test',
            hostname: 'host',
            cloakedHost: 'cloak',
            ipBase64: 'dGVzdA==',
            isIdentified: false,
            serverSid: '001',
        );

        $this->networkUserLookup
            ->expects(self::once())
            ->method('findByUid')
            ->with('001ABC')
            ->willReturn($senderView);

        $this->notifier->expects(self::never())->method('setUserVhost');

        $this->subscriber->onUserJoined($event);

        self::assertFalse($this->burstState->isComplete());
        $pending = $this->burstState->takePending();
        self::assertCount(1, $pending);
        self::assertSame($senderView, $pending[0]);
    }

    #[Test]
    public function onUserJoinedSyncsVhostAndRunsProtectionWhenBurstComplete(): void
    {
        $this->burstState->markComplete();
        $dto = $this->createUserJoinedDTO('001ABC');
        $event = new UserJoinedNetworkAppEvent($dto);
        $senderView = new SenderView(
            uid: '001ABC',
            nick: 'Test',
            ident: 'test',
            hostname: 'host',
            cloakedHost: 'cloak',
            ipBase64: 'dGVzdA==',
            isIdentified: false,
            serverSid: '001',
        );

        $this->networkUserLookup
            ->expects(self::once())
            ->method('findByUid')
            ->with('001ABC')
            ->willReturn($senderView);

        $this->notifier
            ->expects(self::atLeastOnce())
            ->method('setUserVhost')
            ->with('001ABC', '', '001');

        $this->subscriber->onUserJoined($event);
    }

    #[Test]
    public function onBurstCompleteMarksCompleteAndProcessesPendingUsers(): void
    {
        $senderView = new SenderView(
            uid: '001ABC',
            nick: 'Test',
            ident: 'test',
            hostname: 'host',
            cloakedHost: 'cloak',
            ipBase64: 'dGVzdA==',
            isIdentified: false,
            serverSid: '001',
        );
        $this->burstState->addPending($senderView);

        $connection = $this->createStub(ConnectionInterface::class);
        $event = new NetworkBurstCompleteEvent($connection, '001');

        $this->networkUserLookup->expects(self::never())->method('findByUid');
        $this->notifier
            ->expects(self::atLeastOnce())
            ->method('setUserVhost');

        $this->subscriber->onBurstComplete($event);

        self::assertTrue($this->burstState->isComplete());
        self::assertCount(0, $this->burstState->takePending());
    }

    #[Test]
    public function onBurstCompleteDoesNothingWhenNoPendingUsers(): void
    {
        $connection = $this->createStub(ConnectionInterface::class);
        $event = new NetworkBurstCompleteEvent($connection, '001');

        $this->networkUserLookup->expects(self::never())->method('findByUid');
        $this->notifier->expects(self::never())->method('setUserVhost');

        $this->subscriber->onBurstComplete($event);

        self::assertTrue($this->burstState->isComplete());
    }

    #[Test]
    public function onNickChangedDelegatesToNickProtectionServiceAndSyncsVhostWhenUserFound(): void
    {
        $senderView = new SenderView(
            uid: '001ABC',
            nick: 'NewNick',
            ident: 'test',
            hostname: 'host',
            cloakedHost: 'cloak',
            ipBase64: 'dGVzdA==',
            isIdentified: false,
            serverSid: '001',
        );

        $this->networkUserLookup->expects(self::once())
            ->method('findByUid')
            ->with('001ABC')
            ->willReturn($senderView);

        $this->notifier->expects(self::once())
            ->method('setUserVhost')
            ->with('001ABC', '', '001');

        $event = new UserNickChangedEvent(
            new Uid('001ABC'),
            new Nick('OldNick'),
            new Nick('NewNick'),
        );

        $this->subscriber->onNickChanged($event);
    }

    #[Test]
    public function onUserModeChangedIgnoresNonIdentifiedModeDeltas(): void
    {
        $this->networkUserLookup->expects(self::never())->method('findByUid');
        $this->notifier->expects(self::never())->method('setUserVhost');

        $event = new UserModeChangedEvent(new Uid('001ABC'), '+i');
        $this->subscriber->onUserModeChanged($event);

        $eventMinus = new UserModeChangedEvent(new Uid('001ABC'), '-r');
        $this->subscriber->onUserModeChanged($eventMinus);
    }

    #[Test]
    public function onUserModeChangedDoesNothingWhenUserNotFound(): void
    {
        $this->networkUserLookup->expects(self::once())
            ->method('findByUid')
            ->with('001ABC')
            ->willReturn(null);

        $this->notifier->expects(self::never())->method('setUserVhost');

        $event = new UserModeChangedEvent(new Uid('001ABC'), '+r');
        $this->subscriber->onUserModeChanged($event);
    }

    #[Test]
    public function onUserModeChangedDoesNothingWhenUserNotIdentified(): void
    {
        $senderView = new SenderView(
            uid: '001ABC',
            nick: 'Test',
            ident: 'test',
            hostname: 'host',
            cloakedHost: 'cloak',
            ipBase64: 'dGVzdA==',
            isIdentified: false,
            serverSid: '001',
        );

        $this->networkUserLookup->expects(self::once())
            ->method('findByUid')
            ->with('001ABC')
            ->willReturn($senderView);

        $this->notifier->expects(self::never())->method('setUserVhost');

        $event = new UserModeChangedEvent(new Uid('001ABC'), '+r');
        $this->subscriber->onUserModeChanged($event);
    }

    #[Test]
    public function onUserModeChangedSyncsVhostAndEnforcesProtectionWhenIdentified(): void
    {
        $senderView = new SenderView(
            uid: '001ABC',
            nick: 'Test',
            ident: 'test',
            hostname: 'host',
            cloakedHost: 'cloak',
            ipBase64: 'dGVzdA==',
            isIdentified: true,
            serverSid: '001',
        );

        $this->networkUserLookup->expects(self::once())
            ->method('findByUid')
            ->with('001ABC')
            ->willReturn($senderView);

        $this->notifier->expects(self::never())->method('setUserVhost');

        $event = new UserModeChangedEvent(new Uid('001ABC'), '+r');
        $this->subscriber->onUserModeChanged($event);
    }

    #[Test]
    public function onUserQuitDelegatesToNickProtectionService(): void
    {
        $this->networkUserLookup->expects(self::never())->method('findByUid');
        $this->notifier->expects(self::never())->method('setUserVhost');
        $event = new UserQuitNetworkEvent(
            new Uid('001ABC'),
            new Nick('User'),
            'Quit reason',
            'ident',
            'display.host',
        );

        $this->subscriber->onUserQuit($event);
        self::assertTrue(true, 'No exception when delegating onUserQuit');
    }

    #[Test]
    public function onNickChangedDefersProtectionCheckWhenProtocolPreservesIdentificationAndUserNotIdentified(): void
    {
        $this->notifier->expects(self::never())->method('setUserVhost');
        $senderView = new SenderView(
            uid: '001ABC',
            nick: 'NewNick',
            ident: 'test',
            hostname: 'host',
            cloakedHost: 'cloak',
            ipBase64: 'dGVzdA==',
            isIdentified: false,
            serverSid: '001',
        );

        $this->networkUserLookup->expects(self::once())
            ->method('findByUid')
            ->with('001ABC')
            ->willReturn($senderView);

        $pendingRegistry = $this->createMock(PendingNickProtectionRegistryInterface::class);
        $pendingRegistry->expects(self::once())->method('schedule')->with('001ABC');

        $module = $this->createStub(NickProtectionTestProtocolModule::class);
        $connectionHolder = $this->createStub(ActiveConnectionHolderInterface::class);
        $connectionHolder->method('getProtocolModule')->willReturn($module);

        $subscriber = new NickProtectionSubscriber(
            $this->createNickProtectionService(),
            $this->createVhostSyncService(),
            $this->burstState,
            $this->networkUserLookup,
            $connectionHolder,
            $pendingRegistry,
        );

        $event = new UserNickChangedEvent(
            new Uid('001ABC'),
            new Nick('OldNick'),
            new Nick('NewNick'),
        );

        $subscriber->onNickChanged($event);
    }

    #[Test]
    public function onUserJoinedDefersProtectionCheckWhenProtocolPreservesIdentificationAndUserNotIdentified(): void
    {
        $this->notifier->expects(self::never())->method('setUserVhost');
        $senderView = new SenderView(
            uid: '001ABC',
            nick: 'NewNick',
            ident: 'test',
            hostname: 'host',
            cloakedHost: 'cloak',
            ipBase64: 'dGVzdA==',
            isIdentified: false,
            serverSid: '001',
        );

        $this->networkUserLookup->expects(self::once())
            ->method('findByUid')
            ->with('001ABC')
            ->willReturn($senderView);

        $pendingRegistry = $this->createMock(PendingNickProtectionRegistryInterface::class);
        $pendingRegistry->expects(self::once())->method('schedule')->with('001ABC');

        $module = $this->createStub(NickProtectionTestProtocolModule::class);
        $connectionHolder = $this->createStub(ActiveConnectionHolderInterface::class);
        $connectionHolder->method('getProtocolModule')->willReturn($module);

        $burstState = new BurstState();
        $burstState->markComplete();

        $subscriber = new NickProtectionSubscriber(
            $this->createNickProtectionService(),
            $this->createVhostSyncService(),
            $burstState,
            $this->networkUserLookup,
            $connectionHolder,
            $pendingRegistry,
        );

        $dto = $this->createUserJoinedDTO('001ABC');
        $subscriber->onUserJoined(new UserJoinedNetworkAppEvent($dto));
    }

    #[Test]
    public function onUserJoinedWhenIdentifiedDoesNotDeferProtectionCheck(): void
    {
        $this->notifier->expects(self::never())->method('setUserVhost');
        $senderView = new SenderView(
            uid: '001ABC',
            nick: 'NewNick',
            ident: 'test',
            hostname: 'host',
            cloakedHost: 'cloak',
            ipBase64: 'dGVzdA==',
            isIdentified: true,
            serverSid: '001',
        );

        $this->networkUserLookup->expects(self::once())
            ->method('findByUid')
            ->with('001ABC')
            ->willReturn($senderView);

        $pendingRegistry = $this->createMock(PendingNickProtectionRegistryInterface::class);
        $pendingRegistry->expects(self::never())->method('schedule');

        $burstState = new BurstState();
        $burstState->markComplete();

        $subscriber = new NickProtectionSubscriber(
            $this->createNickProtectionService(),
            $this->createVhostSyncService(),
            $burstState,
            $this->networkUserLookup,
            $this->createStub(ActiveConnectionHolderInterface::class),
            $pendingRegistry,
        );

        $dto = $this->createUserJoinedDTO('001ABC');
        $subscriber->onUserJoined(new UserJoinedNetworkAppEvent($dto));
    }

    #[Test]
    public function onNickChangedWhenIdentifiedDoesNotDeferProtectionCheck(): void
    {
        $this->notifier->expects(self::never())->method('setUserVhost');
        $senderView = new SenderView(
            uid: '001ABC',
            nick: 'NewNick',
            ident: 'test',
            hostname: 'host',
            cloakedHost: 'cloak',
            ipBase64: 'dGVzdA==',
            isIdentified: true,
            serverSid: '001',
        );

        $this->networkUserLookup->expects(self::once())
            ->method('findByUid')
            ->with('001ABC')
            ->willReturn($senderView);

        $pendingRegistry = $this->createMock(PendingNickProtectionRegistryInterface::class);
        $pendingRegistry->expects(self::never())->method('schedule');

        $burstState = new BurstState();
        $burstState->markComplete();

        $subscriber = new NickProtectionSubscriber(
            $this->createNickProtectionService(),
            $this->createVhostSyncService(),
            $burstState,
            $this->networkUserLookup,
            $this->createStub(ActiveConnectionHolderInterface::class),
            $pendingRegistry,
        );

        $event = new UserNickChangedEvent(
            new Uid('001ABC'),
            new Nick('OldNick'),
            new Nick('NewNick'),
        );

        $subscriber->onNickChanged($event);
    }

    #[Test]
    public function onNickChangedWhenIdentifiedAndProtocolPreservesIdentificationDoesNotSyncVhost(): void
    {
        $this->notifier->expects(self::never())->method('setUserVhost');
        $senderView = new SenderView(
            uid: '001ABC',
            nick: 'NewNick',
            ident: 'test',
            hostname: 'host',
            cloakedHost: 'cloak',
            ipBase64: 'dGVzdA==',
            isIdentified: true,
            serverSid: '001',
        );

        $this->networkUserLookup->expects(self::once())
            ->method('findByUid')
            ->with('001ABC')
            ->willReturn($senderView);

        $pendingRegistry = $this->createMock(PendingNickProtectionRegistryInterface::class);
        $pendingRegistry->expects(self::never())->method('schedule');

        $module = $this->createStub(NickProtectionTestProtocolModule::class);
        $connectionHolder = $this->createStub(ActiveConnectionHolderInterface::class);
        $connectionHolder->method('getProtocolModule')->willReturn($module);

        $burstState = new BurstState();
        $burstState->markComplete();

        $notifier = $this->createMock(NickServNotifierInterface::class);
        $notifier->expects(self::never())->method('setUserVhost');

        $account = RegisteredNick::createPending('NewNick', 'hash', 'u@e.com', 'en', new DateTimeImmutable('+1 hour'));
        $account->activate();
        $account->changeVhost('test.vhost');

        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepo->method('findByNick')->willReturn($account);

        $vhostSync = new IdentifiedUserVhostSyncService(
            $nickRepo,
            $notifier,
            new VhostDisplayResolver(),
            $this->createStub(OperIrcopRepositoryInterface::class),
            $connectionHolder,
        );

        $subscriber = new NickProtectionSubscriber(
            $this->createNickProtectionService(),
            $vhostSync,
            $burstState,
            $this->networkUserLookup,
            $connectionHolder,
            $pendingRegistry,
        );

        $event = new UserNickChangedEvent(
            new Uid('001ABC'),
            new Nick('OldNick'),
            new Nick('NewNick'),
        );

        $subscriber->onNickChanged($event);
    }

    #[Test]
    public function onUserModeChangedCancelsPendingProtectionCheck(): void
    {
        $this->notifier->expects(self::never())->method('setUserVhost');
        $pendingRegistry = $this->createMock(PendingNickProtectionRegistryInterface::class);
        $pendingRegistry->expects(self::once())->method('cancel')->with('001ABC');

        $this->networkUserLookup->expects(self::once())
            ->method('findByUid')
            ->with('001ABC')
            ->willReturn(null);

        $subscriber = new NickProtectionSubscriber(
            $this->createNickProtectionService(),
            $this->createVhostSyncService(),
            $this->burstState,
            $this->networkUserLookup,
            $this->createStub(ActiveConnectionHolderInterface::class),
            $pendingRegistry,
        );

        $event = new UserModeChangedEvent(new Uid('001ABC'), '+r');
        $subscriber->onUserModeChanged($event);
    }

    #[Test]
    public function onUserQuitCancelsPendingProtectionCheck(): void
    {
        $this->notifier->expects(self::never())->method('setUserVhost');
        $this->networkUserLookup->expects(self::never())->method('findByUid');
        $pendingRegistry = $this->createMock(PendingNickProtectionRegistryInterface::class);
        $pendingRegistry->expects(self::once())->method('cancel')->with('001ABC');

        $subscriber = new NickProtectionSubscriber(
            $this->createNickProtectionService(),
            $this->createVhostSyncService(),
            $this->burstState,
            $this->networkUserLookup,
            $this->createStub(ActiveConnectionHolderInterface::class),
            $pendingRegistry,
        );

        $event = new UserQuitNetworkEvent(
            new Uid('001ABC'),
            new Nick('User'),
            'Quit reason',
            'ident',
            'display.host',
        );

        $subscriber->onUserQuit($event);
    }

    #[Test]
    public function onIrcMessageProcessedEnforcesProtectionOnExpiredUids(): void
    {
        $this->notifier->expects(self::never())->method('setUserVhost');
        $pendingRegistry = $this->createMock(PendingNickProtectionRegistryInterface::class);
        $pendingRegistry->expects(self::once())->method('flushExpired')->willReturn(['001EXPIRED', '001NOTFOUND', '001IDENTIFIED']);

        $expiredUser = new SenderView(
            uid: '001EXPIRED',
            nick: 'TargetNick',
            ident: 'test',
            hostname: 'host',
            cloakedHost: 'cloak',
            ipBase64: 'dGVzdA==',
            isIdentified: false,
            serverSid: '001',
        );

        $identifiedUser = new SenderView(
            uid: '001IDENTIFIED',
            nick: 'TargetNick2',
            ident: 'test',
            hostname: 'host',
            cloakedHost: 'cloak',
            ipBase64: 'dGVzdA==',
            isIdentified: true,
            serverSid: '001',
        );

        $this->networkUserLookup->expects(self::exactly(3))
            ->method('findByUid')
            ->willReturnMap([
                ['001EXPIRED', $expiredUser],
                ['001NOTFOUND', null],
                ['001IDENTIFIED', $identifiedUser],
            ]);

        $subscriber = new NickProtectionSubscriber(
            $this->createNickProtectionService(),
            $this->createVhostSyncService(),
            $this->burstState,
            $this->networkUserLookup,
            $this->createStub(ActiveConnectionHolderInterface::class),
            $pendingRegistry,
        );

        $subscriber->onIrcMessageProcessed(new IrcMessageProcessedEvent());
    }

    #[Test]
    public function onIrcMessageProcessedDoesNothingWhenNoRegistry(): void
    {
        $this->notifier->expects(self::never())->method('setUserVhost');
        $this->networkUserLookup->expects(self::never())->method('findByUid');

        $subscriber = new NickProtectionSubscriber(
            $this->createNickProtectionService(),
            $this->createVhostSyncService(),
            $this->burstState,
            $this->networkUserLookup,
            $this->createStub(ActiveConnectionHolderInterface::class),
        );

        $subscriber->onIrcMessageProcessed(new IrcMessageProcessedEvent());
        self::assertTrue(true);
    }

    private function createVhostSyncService(): IdentifiedUserVhostSyncService
    {
        return new IdentifiedUserVhostSyncService(
            $this->createStub(RegisteredNickRepositoryInterface::class),
            $this->createStub(NickServNotifierInterface::class),
            new VhostDisplayResolver(),
            $this->createStub(OperIrcopRepositoryInterface::class),
            $this->createStub(ActiveConnectionHolderInterface::class),
        );
    }

    private function createNickProtectionService(): NickProtectionService
    {
        $nickRepository = $this->createStub(RegisteredNickRepositoryInterface::class);
        $userLookup = $this->createStub(NetworkUserLookupPort::class);
        $translator = $this->createStub(TranslationInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);
        $pendingRegistry = $this->createStub(PendingNickRestoreRegistryInterface::class);

        return new NickProtectionService(
            $nickRepository,
            $userLookup,
            $this->createStub(NickServNotifierInterface::class),
            $this->burstState,
            new IdentifiedSessionRegistry(),
            new SessionLanguageRegistry(),
            $pendingRegistry,
            $translator,
            $this->createStub(EventBusInterface::class),
            $this->createStub(ForbiddenNickService::class),
            $this->createStub(ActiveConnectionHolderInterface::class),
        );
    }

    private function createUserJoinedDTO(string $uid): UserJoinedNetworkDTO
    {
        return new UserJoinedNetworkDTO(
            uid: $uid,
            nick: 'Test',
            ident: 'test',
            hostname: 'host.example',
            cloakedHost: 'cloak.example',
            ipBase64: 'dGVzdA==',
            displayHost: 'cloak.example',
            isIdentified: false,
            isOper: false,
            serverSid: '001',
        );
    }
}

interface NickProtectionTestProtocolModule extends ProtocolModuleInterface, NickChangePreservesIdentificationInterface {}
