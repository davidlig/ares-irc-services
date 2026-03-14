<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\ChanServ\Subscriber;

use App\Application\ChanServ\ChanServAccessHelper;
use App\Application\ChanServ\Event\ChannelFounderChangedEvent;
use App\Application\ChanServ\Event\ChannelSecureEnabledEvent;
use App\Application\Port\ActiveChannelModeSupportProviderInterface;
use App\Application\Port\ChannelLookupPort;
use App\Application\Port\ChannelModeSupportInterface;
use App\Application\Port\ChannelServiceActionsPort;
use App\Application\Port\ChannelView;
use App\Application\Port\NetworkUserLookupPort;
use App\Application\Port\SenderView;
use App\Domain\ChanServ\Entity\ChannelLevel;
use App\Domain\ChanServ\Repository\ChannelAccessRepositoryInterface;
use App\Domain\ChanServ\Repository\ChannelLevelRepositoryInterface;
use App\Domain\ChanServ\Repository\RegisteredChannelRepositoryInterface;
use App\Domain\IRC\Connection\ConnectionInterface;
use App\Domain\IRC\Event\IrcMessageProcessedEvent;
use App\Domain\IRC\Event\MessageReceivedEvent;
use App\Domain\IRC\Event\NetworkSyncCompleteEvent;
use App\Domain\IRC\Event\UserJoinedChannelEvent;
use App\Domain\IRC\Message\IRCMessage;
use App\Domain\IRC\Network\ChannelMemberRole;
use App\Domain\IRC\ValueObject\ChannelName;
use App\Domain\IRC\ValueObject\Uid;
use App\Domain\NickServ\Repository\RegisteredNickRepositoryInterface;
use App\Infrastructure\ChanServ\ChannelRankSyncPendingRegistry;
use App\Infrastructure\ChanServ\Subscriber\ChanServChannelRankSubscriber;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ChanServChannelRankSubscriber::class)]
final class ChanServChannelRankSubscriberTest extends TestCase
{
    private const CHANSERV_UID = '001CHAN';

    private const CHANNEL_ID = 1;

    private const NICK_ID = 10;

    private RegisteredChannelRepositoryInterface $channelRepository;

    private NetworkUserLookupPort $userLookup;

    private RegisteredNickRepositoryInterface $nickRepository;

    private ChannelLookupPort $channelLookup;

    private ChannelServiceActionsPort $channelServiceActions;

    private ActiveChannelModeSupportProviderInterface $modeSupportProvider;

    private ChannelAccessRepositoryInterface $accessRepository;

    private ChannelLevelRepositoryInterface $levelRepository;

    private ChanServAccessHelper $accessHelper;

    private ChannelRankSyncPendingRegistry $syncPendingRegistry;

    private ChanServChannelRankSubscriber $subscriber;

    protected function setUp(): void
    {
        $this->channelRepository = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $this->channelRepository->method('listAll')->willReturn([]);
        $this->channelRepository->method('findByChannelName')->willReturn(null);
        $this->userLookup = $this->createStub(NetworkUserLookupPort::class);
        $this->userLookup->method('findByUid')->willReturn(null);
        $this->nickRepository = $this->createStub(RegisteredNickRepositoryInterface::class);
        $this->nickRepository->method('findByNick')->willReturn(null);
        $this->channelLookup = $this->createStub(ChannelLookupPort::class);
        $this->channelLookup->method('findByChannelName')->willReturn(null);
        $this->channelServiceActions = $this->createStub(ChannelServiceActionsPort::class);
        $this->modeSupportProvider = $this->createStub(ActiveChannelModeSupportProviderInterface::class);
        $this->modeSupportProvider->method('getSupport')->willReturn($this->createStub(ChannelModeSupportInterface::class));
        $this->accessRepository = $this->createStub(ChannelAccessRepositoryInterface::class);
        $this->accessRepository->method('findByChannelAndNick')->willReturn(null);
        $this->levelRepository = $this->createStub(ChannelLevelRepositoryInterface::class);
        $this->levelRepository->method('findByChannelAndKey')->willReturn(null);
        $this->accessHelper = new ChanServAccessHelper($this->accessRepository, $this->levelRepository);
        $this->syncPendingRegistry = new ChannelRankSyncPendingRegistry();

        $this->subscriber = new ChanServChannelRankSubscriber(
            $this->channelRepository,
            $this->userLookup,
            $this->nickRepository,
            $this->channelLookup,
            $this->channelServiceActions,
            $this->modeSupportProvider,
            $this->accessHelper,
            $this->syncPendingRegistry,
            self::CHANSERV_UID,
        );
    }

    private function rebuildSubscriber(): void
    {
        $this->accessHelper = new ChanServAccessHelper($this->accessRepository, $this->levelRepository);
        $this->subscriber = new ChanServChannelRankSubscriber(
            $this->channelRepository,
            $this->userLookup,
            $this->nickRepository,
            $this->channelLookup,
            $this->channelServiceActions,
            $this->modeSupportProvider,
            $this->accessHelper,
            $this->syncPendingRegistry,
            self::CHANSERV_UID,
        );
    }

    #[Test]
    public function getSubscribedEventsReturnsExpectedArray(): void
    {
        self::assertSame(
            [
                MessageReceivedEvent::class => ['onMessageReceived', 256],
                IrcMessageProcessedEvent::class => ['onIrcMessageProcessed', -255],
                UserJoinedChannelEvent::class => ['onUserJoinedChannel', 0],
                \App\Domain\IRC\Event\UserLeftChannelEvent::class => ['onUserLeftChannel', 0],
                NetworkSyncCompleteEvent::class => ['onSyncComplete', 0],
                ChannelSecureEnabledEvent::class => ['onChannelSecureEnabled', 0],
                ChannelFounderChangedEvent::class => ['onChannelFounderChanged', 0],
                \App\Domain\IRC\Event\ModeReceivedEvent::class => ['onModeReceived', 255],
            ],
            ChanServChannelRankSubscriber::getSubscribedEvents(),
        );
    }

    #[Test]
    public function onSyncCompleteWithNoRegisteredChannelsDoesNotCallSetChannelModes(): void
    {
        $connection = $this->createStub(ConnectionInterface::class);
        $this->channelServiceActions = $this->createMock(ChannelServiceActionsPort::class);
        $this->channelServiceActions->expects(self::never())->method('setChannelModes');
        $this->rebuildSubscriber();

        $this->subscriber->onSyncComplete(new NetworkSyncCompleteEvent($connection, '001'));
    }

    #[Test]
    public function onSyncCompleteWhenChannelLookupReturnsNullDoesNotCallSetChannelModes(): void
    {
        $channel = $this->createStub(\App\Domain\ChanServ\Entity\RegisteredChannel::class);
        $channel->method('getName')->willReturn('#test');
        $channel->method('getId')->willReturn(self::CHANNEL_ID);
        $channel->method('isSecure')->willReturn(false);

        $connection = $this->createStub(ConnectionInterface::class);
        $this->channelRepository = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $this->channelRepository->method('listAll')->willReturn([$channel]);
        $this->channelLookup = $this->createMock(ChannelLookupPort::class);
        $this->channelLookup->expects(self::atLeastOnce())->method('findByChannelName')->with('#test')->willReturn(null);
        $this->channelServiceActions = $this->createMock(ChannelServiceActionsPort::class);
        $this->channelServiceActions->expects(self::never())->method('setChannelModes');
        $this->rebuildSubscriber();

        $this->subscriber->onSyncComplete(new NetworkSyncCompleteEvent($connection, '001'));
    }

    #[Test]
    public function onSyncCompleteWithRegisteredChannelAndMemberCallsSetChannelModes(): void
    {
        $channel = $this->createMock(\App\Domain\ChanServ\Entity\RegisteredChannel::class);
        $channel->expects(self::atLeastOnce())->method('getName')->willReturn('#test');
        $channel->expects(self::atLeastOnce())->method('getId')->willReturn(self::CHANNEL_ID);
        $channel->expects(self::atLeastOnce())->method('isSecure')->willReturn(false);
        $channel->expects(self::atLeastOnce())->method('isFounder')->with(self::NICK_ID)->willReturn(false);

        $view = new ChannelView(
            name: '#test',
            modes: '+nt',
            topic: null,
            memberCount: 1,
            members: [
                ['uid' => '001USER', 'roleLetter' => '', 'prefixLetters' => []],
            ],
        );
        $modeSupport = $this->createStub(ChannelModeSupportInterface::class);
        $modeSupport->method('getSupportedPrefixModes')->willReturn(['q', 'a', 'o', 'h', 'v']);
        $sender = new SenderView(
            uid: '001USER',
            nick: 'TestUser',
            ident: '~u',
            hostname: 'user.example.com',
            cloakedHost: 'user.example.com',
            ipBase64: '',
            isIdentified: true,
        );
        $account = $this->createStub(\App\Domain\NickServ\Entity\RegisteredNick::class);
        $account->method('getId')->willReturn(self::NICK_ID);
        $accessStub = $this->createStub(\App\Domain\ChanServ\Entity\ChannelAccess::class);
        $accessStub->method('getLevel')->willReturn(100);

        $this->channelRepository = $this->createMock(RegisteredChannelRepositoryInterface::class);
        $this->channelRepository->expects(self::atLeastOnce())->method('listAll')->willReturn([$channel]);
        $this->channelLookup = $this->createMock(ChannelLookupPort::class);
        $this->channelLookup->expects(self::atLeastOnce())->method('findByChannelName')->with('#test')->willReturn($view);
        $this->modeSupportProvider = $this->createMock(ActiveChannelModeSupportProviderInterface::class);
        $this->modeSupportProvider->expects(self::atLeastOnce())->method('getSupport')->willReturn($modeSupport);
        $this->userLookup = $this->createMock(NetworkUserLookupPort::class);
        $this->userLookup->expects(self::atLeastOnce())->method('findByUid')->with('001USER')->willReturn($sender);
        $this->userLookup->expects(self::never())->method('findByNick');
        $this->nickRepository = $this->createMock(RegisteredNickRepositoryInterface::class);
        $this->nickRepository->expects(self::atLeastOnce())->method('findByNick')->with('TestUser')->willReturn($account);
        $this->accessRepository = $this->createMock(ChannelAccessRepositoryInterface::class);
        $this->accessRepository->expects(self::atLeastOnce())->method('findByChannelAndNick')->with(self::CHANNEL_ID, self::NICK_ID)->willReturn($accessStub);
        $this->levelRepository = $this->createMock(ChannelLevelRepositoryInterface::class);
        $this->levelRepository->expects(self::atLeastOnce())->method('findByChannelAndKey')->willReturnCallback(
            static fn (int $channelId, string $key): ?ChannelLevel => match ($key) {
                ChannelLevel::KEY_AUTOADMIN => new ChannelLevel($channelId, $key, 200),
                ChannelLevel::KEY_AUTOOP => new ChannelLevel($channelId, $key, 100),
                default => null,
            },
        );
        $this->channelServiceActions = $this->createMock(ChannelServiceActionsPort::class);
        $this->channelServiceActions->expects(self::atLeastOnce())->method('setChannelModes')->with('#test', self::anything(), self::anything());
        $this->rebuildSubscriber();

        $connection = $this->createStub(ConnectionInterface::class);
        $this->subscriber->onSyncComplete(new NetworkSyncCompleteEvent($connection, '001'));
    }

    #[Test]
    public function onUserJoinedChannelWhenChannelNotRegisteredDoesNotCallSetChannelMemberMode(): void
    {
        $this->channelRepository = $this->createMock(RegisteredChannelRepositoryInterface::class);
        $this->channelRepository->expects(self::atLeastOnce())->method('findByChannelName')->with('#test')->willReturn(null);
        $this->channelServiceActions = $this->createMock(ChannelServiceActionsPort::class);
        $this->channelServiceActions->expects(self::never())->method('setChannelMemberMode');
        $this->rebuildSubscriber();

        $event = new UserJoinedChannelEvent(
            uid: new Uid('001USER'),
            channel: new ChannelName('#test'),
            role: ChannelMemberRole::None,
        );
        $this->subscriber->onUserJoinedChannel($event);
    }

    #[Test]
    public function onUserJoinedChannelWhenRegisteredAndIdentifiedGrantsDesiredPrefix(): void
    {
        $channel = $this->createMock(\App\Domain\ChanServ\Entity\RegisteredChannel::class);
        $channel->expects(self::never())->method('getName');
        $channel->expects(self::atLeastOnce())->method('getId')->willReturn(self::CHANNEL_ID);
        $channel->expects(self::atLeastOnce())->method('isSecure')->willReturn(false);
        $channel->expects(self::atLeastOnce())->method('isFounder')->with(self::NICK_ID)->willReturn(false);
        $sender = new SenderView(
            uid: '001USER',
            nick: 'TestUser',
            ident: '~u',
            hostname: 'user.example.com',
            cloakedHost: 'user.example.com',
            ipBase64: '',
            isIdentified: true,
        );
        $account = $this->createStub(\App\Domain\NickServ\Entity\RegisteredNick::class);
        $account->method('getId')->willReturn(self::NICK_ID);
        $accessStub = $this->createStub(\App\Domain\ChanServ\Entity\ChannelAccess::class);
        $accessStub->method('getLevel')->willReturn(100);
        $modeSupport = $this->createStub(ChannelModeSupportInterface::class);
        $modeSupport->method('getSupportedPrefixModes')->willReturn(['q', 'a', 'o', 'h', 'v']);

        $this->channelRepository = $this->createMock(RegisteredChannelRepositoryInterface::class);
        $this->channelRepository->expects(self::atLeastOnce())->method('findByChannelName')->with('#test')->willReturn($channel);
        $this->channelRepository->expects(self::once())->method('save')->with($channel);
        $this->userLookup = $this->createMock(NetworkUserLookupPort::class);
        $this->userLookup->expects(self::atLeastOnce())->method('findByUid')->with('001USER')->willReturn($sender);
        $this->userLookup->expects(self::never())->method('findByNick');
        $this->nickRepository = $this->createMock(RegisteredNickRepositoryInterface::class);
        $this->nickRepository->expects(self::atLeastOnce())->method('findByNick')->with('TestUser')->willReturn($account);
        $this->accessRepository = $this->createMock(ChannelAccessRepositoryInterface::class);
        $this->accessRepository->expects(self::atLeastOnce())->method('findByChannelAndNick')->with(self::CHANNEL_ID, self::NICK_ID)->willReturn($accessStub);
        $this->levelRepository = $this->createMock(ChannelLevelRepositoryInterface::class);
        $this->levelRepository->expects(self::atLeastOnce())->method('findByChannelAndKey')->willReturnCallback(
            static fn (int $channelId, string $key): ?ChannelLevel => match ($key) {
                ChannelLevel::KEY_AUTOADMIN => new ChannelLevel($channelId, $key, 200),
                ChannelLevel::KEY_AUTOOP => new ChannelLevel($channelId, $key, 100),
                default => null,
            },
        );
        $this->modeSupportProvider = $this->createStub(ActiveChannelModeSupportProviderInterface::class);
        $this->modeSupportProvider->method('getSupport')->willReturn($modeSupport);
        $this->channelServiceActions = $this->createMock(ChannelServiceActionsPort::class);
        $this->channelServiceActions->expects(self::once())->method('setChannelMemberMode')->with('#test', '001USER', 'o', true);
        $this->rebuildSubscriber();

        $event = new UserJoinedChannelEvent(
            uid: new Uid('001USER'),
            channel: new ChannelName('#test'),
            role: ChannelMemberRole::None,
        );
        $this->subscriber->onUserJoinedChannel($event);
    }

    #[Test]
    public function onMessageReceivedSnapshotsPendingRegistry(): void
    {
        $this->syncPendingRegistry->add('#test');
        $this->subscriber->onMessageReceived(new MessageReceivedEvent(new IRCMessage('PING')));
        self::assertSame(['#test'], $this->syncPendingRegistry->getPendingAtStart());
    }

    #[Test]
    public function onIrcMessageProcessedSyncsPendingChannelsAndRemovesThem(): void
    {
        $this->syncPendingRegistry->add('#test');
        $this->syncPendingRegistry->snapshotPendingAtStart();

        $channel = $this->createStub(\App\Domain\ChanServ\Entity\RegisteredChannel::class);
        $channel->method('getName')->willReturn('#test');
        $channel->method('getId')->willReturn(self::CHANNEL_ID);
        $channel->method('isSecure')->willReturn(false);
        $view = new ChannelView('#test', '+nt', null, 0, []);

        $this->channelRepository = $this->createMock(RegisteredChannelRepositoryInterface::class);
        $this->channelRepository->expects(self::atLeastOnce())->method('findByChannelName')->with('#test')->willReturn($channel);
        $this->channelLookup = $this->createMock(ChannelLookupPort::class);
        $this->channelLookup->expects(self::atLeastOnce())->method('findByChannelName')->with('#test')->willReturn($view);
        $this->channelServiceActions = $this->createMock(ChannelServiceActionsPort::class);
        $this->channelServiceActions->expects(self::never())->method('setChannelModes');
        $this->rebuildSubscriber();

        $this->subscriber->onIrcMessageProcessed(new IrcMessageProcessedEvent());

        self::assertSame([], $this->syncPendingRegistry->getPendingAtStart());
    }
}
