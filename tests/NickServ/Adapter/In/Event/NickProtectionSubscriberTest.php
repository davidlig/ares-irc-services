<?php

declare(strict_types=1);

namespace App\Tests\NickServ\Adapter\In\Event;

use App\Irc\Application\Port\In\ActiveProtocolModuleHolderInterface;
use App\Irc\Application\Port\In\NetworkUserLookupPort;
use App\Irc\Application\Port\In\NickChangePreservesIdentificationInterface;
use App\Irc\Application\Port\In\ProtocolModuleInterface;
use App\Irc\Application\Port\In\SenderView;
use App\Irc\Application\PublishedEvent\IrcMessageHandledEvent;
use App\Irc\Application\PublishedEvent\NetworkSynchronizationCompletedEvent;
use App\Irc\Application\PublishedEvent\UserJoinedNetworkAppEvent;
use App\Irc\Application\PublishedEvent\UserJoinedNetworkDTO;
use App\Irc\Application\PublishedEvent\UserLeftNetworkEvent;
use App\Irc\Application\PublishedEvent\UserModesChangedEvent;
use App\Irc\Application\PublishedEvent\UserNicknameChangedEvent;
use App\NickServ\Adapter\In\Event\NickProtectionSubscriber;
use App\NickServ\Adapter\Out\InMemory\IdentifiedSessionRegistry;
use App\NickServ\Adapter\Out\InMemory\SessionLanguageRegistry;
use App\NickServ\Application\Model\NetworkUser;
use App\NickServ\Application\Port\Out\Clock;
use App\NickServ\Application\Port\Out\ForcedVhostCheckerInterface;
use App\NickServ\Application\Port\Out\GuestNicknameGenerator;
use App\NickServ\Application\Port\Out\NickChangeIdentificationPolicy;
use App\NickServ\Application\Port\Out\NickNetworkActions;
use App\NickServ\Application\Port\Out\NickNetworkUserLookup;
use App\NickServ\Application\Port\Out\NickProtectionNotifier;
use App\NickServ\Application\Port\Out\NickServEventPublisher;
use App\NickServ\Application\Port\Out\PendingNickProtectionRegistryInterface;
use App\NickServ\Application\Port\Out\PendingNickRestoreRegistryInterface;
use App\NickServ\Application\Port\Out\RegisteredNickRepositoryInterface;
use App\NickServ\Application\Service\BurstState;
use App\NickServ\Application\Service\ForbiddenNickService;
use App\NickServ\Application\Service\IdentifiedUserVhostSyncService;
use App\NickServ\Application\Service\NickProtectionService;
use App\NickServ\Application\Service\VhostDisplayResolver;
use App\NickServ\Domain\Entity\RegisteredNick;
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

    private MockObject&NickNetworkActions $notifier;

    private NickProtectionSubscriber $subscriber;

    private Clock $clock;

    private DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->burstState = new BurstState();
        $this->networkUserLookup = $this->createMock(NetworkUserLookupPort::class);
        $this->notifier = $this->createMock(NickNetworkActions::class);
        $this->now = new DateTimeImmutable('2026-01-02 03:04:05');
        $this->clock = $this->createStub(Clock::class);
        $this->clock->method('now')->willReturn($this->now);

        $nickRepository = $this->createStub(RegisteredNickRepositoryInterface::class);
        $userLookup = $this->createStub(NickNetworkUserLookup::class);
        $translator = $this->createStub(NickProtectionNotifier::class);
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
            $this->createStub(NickServEventPublisher::class),
            $this->createStub(ForbiddenNickService::class),
            $this->createStub(NickChangeIdentificationPolicy::class),
            $this->guestNicknameGenerator(),
        );

        $ircopRepository = $this->createStub(ForcedVhostCheckerInterface::class);

        $nickChangePolicy = $this->createStub(NickChangeIdentificationPolicy::class);
        $vhostSync = new IdentifiedUserVhostSyncService(
            $nickRepository,
            $this->notifier,
            new VhostDisplayResolver(),
            $ircopRepository,
            $nickChangePolicy,
        );

        $this->subscriber = new NickProtectionSubscriber(
            $nickProtectionService,
            $vhostSync,
            $this->burstState,
            $this->networkUserLookup,
            $this->createStub(ActiveProtocolModuleHolderInterface::class),
            $this->clock,
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
                UserLeftNetworkEvent::class => ['onUserQuit', 0],
                UserNicknameChangedEvent::class => ['onNickChanged', 0],
                UserModesChangedEvent::class => ['onUserModeChanged', 0],
                NetworkSynchronizationCompletedEvent::class => ['onBurstComplete', -256],
                IrcMessageHandledEvent::class => ['onIrcMessageProcessed', -200],
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
        self::assertSame($senderView->uid, $pending[0]->uid);
        self::assertSame($senderView->nick, $pending[0]->nick);
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
        $senderView = new NetworkUser(
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

        $event = new NetworkSynchronizationCompletedEvent('001');

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
        $event = new NetworkSynchronizationCompletedEvent('001');

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

        $event = new UserNicknameChangedEvent('001ABC', 'OldNick', 'NewNick');

        $this->subscriber->onNickChanged($event);
    }

    #[Test]
    public function onUserModeChangedIgnoresNonIdentifiedModeDeltas(): void
    {
        $this->networkUserLookup->expects(self::never())->method('findByUid');
        $this->notifier->expects(self::never())->method('setUserVhost');

        $event = new UserModesChangedEvent('001ABC', '+i');
        $this->subscriber->onUserModeChanged($event);

        $eventMinus = new UserModesChangedEvent('001ABC', '-r');
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

        $event = new UserModesChangedEvent('001ABC', '+r');
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

        $event = new UserModesChangedEvent('001ABC', '+r');
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

        $event = new UserModesChangedEvent('001ABC', '+r');
        $this->subscriber->onUserModeChanged($event);
    }

    #[Test]
    public function onUserQuitDelegatesToNickProtectionService(): void
    {
        $this->networkUserLookup->expects(self::never())->method('findByUid');
        $this->notifier->expects(self::never())->method('setUserVhost');
        $event = new UserLeftNetworkEvent(
            '001ABC',
            'User',
            'Quit reason',
            'ident',
            'display.host',
        );

        $this->subscriber->onUserQuit($event);
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
        $pendingRegistry->expects(self::once())->method('schedule')->with('001ABC', $this->now);

        $module = $this->createStub(NickProtectionTestProtocolModule::class);
        $connectionHolder = $this->createStub(ActiveProtocolModuleHolderInterface::class);
        $connectionHolder->method('getProtocolModule')->willReturn($module);

        $subscriber = new NickProtectionSubscriber(
            $this->createNickProtectionService(),
            $this->createVhostSyncService(),
            $this->burstState,
            $this->networkUserLookup,
            $connectionHolder,
            $this->clock,
            $pendingRegistry,
        );

        $event = new UserNicknameChangedEvent('001ABC', 'OldNick', 'NewNick');

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
        $pendingRegistry->expects(self::once())->method('schedule')->with('001ABC', $this->now);

        $module = $this->createStub(NickProtectionTestProtocolModule::class);
        $connectionHolder = $this->createStub(ActiveProtocolModuleHolderInterface::class);
        $connectionHolder->method('getProtocolModule')->willReturn($module);

        $burstState = new BurstState();
        $burstState->markComplete();

        $subscriber = new NickProtectionSubscriber(
            $this->createNickProtectionService(),
            $this->createVhostSyncService(),
            $burstState,
            $this->networkUserLookup,
            $connectionHolder,
            $this->clock,
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
            $this->createStub(ActiveProtocolModuleHolderInterface::class),
            $this->clock,
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
            $this->createStub(ActiveProtocolModuleHolderInterface::class),
            $this->clock,
            $pendingRegistry,
        );

        $event = new UserNicknameChangedEvent('001ABC', 'OldNick', 'NewNick');

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
        $connectionHolder = $this->createStub(ActiveProtocolModuleHolderInterface::class);
        $connectionHolder->method('getProtocolModule')->willReturn($module);

        $burstState = new BurstState();
        $burstState->markComplete();

        $notifier = $this->createMock(NickNetworkActions::class);
        $notifier->expects(self::never())->method('setUserVhost');

        $account = RegisteredNick::createPending(
            'NewNick',
            'hash',
            'u@e.com',
            'en',
            new DateTimeImmutable('+1 hour'),
            new DateTimeImmutable()
        );
        $account->activate();
        $account->changeVhost('test.vhost');

        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepo->method('findByNick')->willReturn($account);

        $nickChangePolicy = $this->createStub(NickChangeIdentificationPolicy::class);
        $nickChangePolicy->method('preservesIdentification')->willReturn(true);
        $vhostSync = new IdentifiedUserVhostSyncService(
            $nickRepo,
            $notifier,
            new VhostDisplayResolver(),
            $this->createStub(ForcedVhostCheckerInterface::class),
            $nickChangePolicy,
        );

        $subscriber = new NickProtectionSubscriber(
            $this->createNickProtectionService(),
            $vhostSync,
            $burstState,
            $this->networkUserLookup,
            $connectionHolder,
            $this->clock,
            $pendingRegistry,
        );

        $event = new UserNicknameChangedEvent('001ABC', 'OldNick', 'NewNick');

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
            $this->createStub(ActiveProtocolModuleHolderInterface::class),
            $this->clock,
            $pendingRegistry,
        );

        $event = new UserModesChangedEvent('001ABC', '+r');
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
            $this->createStub(ActiveProtocolModuleHolderInterface::class),
            $this->clock,
            $pendingRegistry,
        );

        $event = new UserLeftNetworkEvent(
            '001ABC',
            'User',
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
        $pendingRegistry->expects(self::once())->method('flushExpired')->with($this->now)->willReturn(['001EXPIRED', '001NOTFOUND', '001IDENTIFIED']);

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
            $this->createStub(ActiveProtocolModuleHolderInterface::class),
            $this->clock,
            $pendingRegistry,
        );

        $subscriber->onIrcMessageProcessed(new IrcMessageHandledEvent());
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
            $this->createStub(ActiveProtocolModuleHolderInterface::class),
            $this->clock,
        );

        $subscriber->onIrcMessageProcessed(new IrcMessageHandledEvent());
    }

    private function createVhostSyncService(): IdentifiedUserVhostSyncService
    {
        return new IdentifiedUserVhostSyncService(
            $this->createStub(RegisteredNickRepositoryInterface::class),
            $this->createStub(NickNetworkActions::class),
            new VhostDisplayResolver(),
            $this->createStub(ForcedVhostCheckerInterface::class),
            $this->createStub(NickChangeIdentificationPolicy::class),
        );
    }

    private function createNickProtectionService(): NickProtectionService
    {
        $nickRepository = $this->createStub(RegisteredNickRepositoryInterface::class);
        $userLookup = $this->createStub(NickNetworkUserLookup::class);
        $translator = $this->createStub(NickProtectionNotifier::class);
        $pendingRegistry = $this->createStub(PendingNickRestoreRegistryInterface::class);

        return new NickProtectionService(
            $nickRepository,
            $userLookup,
            $this->createStub(NickNetworkActions::class),
            $this->burstState,
            new IdentifiedSessionRegistry(),
            new SessionLanguageRegistry(),
            $pendingRegistry,
            $translator,
            $this->createStub(NickServEventPublisher::class),
            $this->createStub(ForbiddenNickService::class),
            $this->createStub(NickChangeIdentificationPolicy::class),
            $this->guestNicknameGenerator(),
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

    private function guestNicknameGenerator(): GuestNicknameGenerator
    {
        $generator = $this->createStub(GuestNicknameGenerator::class);
        $generator->method('generate')->willReturnCallback(
            static fn (string $prefix): string => $prefix . 'ABC1234',
        );

        return $generator;
    }
}

interface NickProtectionTestProtocolModule extends ProtocolModuleInterface, NickChangePreservesIdentificationInterface {}
