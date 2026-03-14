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
use App\Domain\IRC\Event\IrcMessageProcessedEvent;
use App\Domain\IRC\Event\MessageReceivedEvent;
use App\Domain\IRC\Event\NetworkSyncCompleteEvent;
use App\Domain\IRC\Message\IRCMessage;
use App\Domain\IRC\Event\UserJoinedChannelEvent;
use App\Domain\IRC\Network\ChannelMemberRole;
use App\Domain\IRC\Connection\ConnectionInterface;
use App\Domain\IRC\ValueObject\ChannelName;
use App\Domain\IRC\ValueObject\Uid;
use App\Domain\NickServ\Repository\RegisteredNickRepositoryInterface;
use App\Infrastructure\ChanServ\ChannelRankSyncPendingRegistry;
use App\Infrastructure\ChanServ\Subscriber\ChanServChannelRankSubscriber;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[CoversClass(ChanServChannelRankSubscriber::class)]
final class ChanServChannelRankSubscriberTest extends TestCase
{
    private const CHANSERV_UID = '001CHAN';
    private const CHANNEL_ID = 1;
    private const NICK_ID = 10;

    private RegisteredChannelRepositoryInterface&MockObject $channelRepository;

    private NetworkUserLookupPort&MockObject $userLookup;

    private RegisteredNickRepositoryInterface&MockObject $nickRepository;

    private ChannelLookupPort&MockObject $channelLookup;

    private ChannelServiceActionsPort&MockObject $channelServiceActions;

    private ActiveChannelModeSupportProviderInterface&MockObject $modeSupportProvider;

    private ChannelAccessRepositoryInterface&MockObject $accessRepository;

    private ChannelLevelRepositoryInterface&MockObject $levelRepository;

    private ChanServAccessHelper $accessHelper;

    private ChannelRankSyncPendingRegistry $syncPendingRegistry;

    private ChanServChannelRankSubscriber $subscriber;

    protected function setUp(): void
    {
        $this->channelRepository = $this->createMock(RegisteredChannelRepositoryInterface::class);
        $this->userLookup = $this->createMock(NetworkUserLookupPort::class);
        $this->nickRepository = $this->createMock(RegisteredNickRepositoryInterface::class);
        $this->channelLookup = $this->createMock(ChannelLookupPort::class);
        $this->channelServiceActions = $this->createMock(ChannelServiceActionsPort::class);
        $this->modeSupportProvider = $this->createMock(ActiveChannelModeSupportProviderInterface::class);
        $this->accessRepository = $this->createMock(ChannelAccessRepositoryInterface::class);
        $this->levelRepository = $this->createMock(ChannelLevelRepositoryInterface::class);
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

    #[Test]
    public function getSubscribedEvents_returns_expected_array(): void
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
    public function onSyncComplete_with_no_registered_channels_does_not_call_setChannelModes(): void
    {
        $connection = $this->createStub(ConnectionInterface::class);
        $this->channelRepository->method('listAll')->willReturn([]);
        $this->channelServiceActions->expects(self::never())->method('setChannelModes');

        $this->subscriber->onSyncComplete(new NetworkSyncCompleteEvent($connection, '001'));
    }

    #[Test]
    public function onSyncComplete_when_channel_lookup_returns_null_does_not_call_setChannelModes(): void
    {
        $channel = $this->createMock(\App\Domain\ChanServ\Entity\RegisteredChannel::class);
        $channel->method('getName')->willReturn('#test');
        $channel->method('getId')->willReturn(self::CHANNEL_ID);
        $channel->method('isSecure')->willReturn(false);

        $connection = $this->createStub(ConnectionInterface::class);
        $this->channelRepository->method('listAll')->willReturn([$channel]);
        $this->channelLookup->method('findByChannelName')->with('#test')->willReturn(null);
        $this->channelServiceActions->expects(self::never())->method('setChannelModes');

        $this->subscriber->onSyncComplete(new NetworkSyncCompleteEvent($connection, '001'));
    }

    #[Test]
    public function onSyncComplete_with_registered_channel_and_member_calls_setChannelModes(): void
    {
        $channel = $this->createMock(\App\Domain\ChanServ\Entity\RegisteredChannel::class);
        $channel->method('getName')->willReturn('#test');
        $channel->method('getId')->willReturn(self::CHANNEL_ID);
        $channel->method('isSecure')->willReturn(false);
        $channel->method('isFounder')->with(self::NICK_ID)->willReturn(false);

        $view = new ChannelView(
            name: '#test',
            modes: '+nt',
            topic: null,
            memberCount: 1,
            members: [
                ['uid' => '001USER', 'roleLetter' => '', 'prefixLetters' => []],
            ],
        );
        $modeSupport = $this->createMock(ChannelModeSupportInterface::class);
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

        $this->channelRepository->method('listAll')->willReturn([$channel]);
        $this->channelLookup->method('findByChannelName')->with('#test')->willReturn($view);
        $this->modeSupportProvider->method('getSupport')->willReturn($modeSupport);
        $this->userLookup->method('findByUid')->with('001USER')->willReturn($sender);
        $this->userLookup->method('findByNick')->with('001USER')->willReturn(null);
        $this->nickRepository->method('findByNick')->with('TestUser')->willReturn($account);
        $this->accessRepository->method('findByChannelAndNick')->with(self::CHANNEL_ID, self::NICK_ID)->willReturn($accessStub);
        $this->levelRepository->method('findByChannelAndKey')->willReturnCallback(
            fn (int $channelId, string $key): ?ChannelLevel => match ($key) {
                ChannelLevel::KEY_AUTOADMIN => new ChannelLevel($channelId, $key, 200),
                ChannelLevel::KEY_AUTOOP => new ChannelLevel($channelId, $key, 100),
                default => null,
            },
        );

        $connection = $this->createStub(ConnectionInterface::class);
        $this->channelServiceActions->expects(self::atLeastOnce())->method('setChannelModes')->with('#test', self::anything(), self::anything());

        $this->subscriber->onSyncComplete(new NetworkSyncCompleteEvent($connection, '001'));
    }

    #[Test]
    public function onUserJoinedChannel_when_channel_not_registered_does_not_call_setChannelMemberMode(): void
    {
        $event = new UserJoinedChannelEvent(
            uid: new Uid('001USER'),
            channel: new ChannelName('#test'),
            role: ChannelMemberRole::None,
        );
        $this->channelRepository->method('findByChannelName')->with('#test')->willReturn(null);
        $this->channelServiceActions->expects(self::never())->method('setChannelMemberMode');

        $this->subscriber->onUserJoinedChannel($event);
    }

    #[Test]
    public function onUserJoinedChannel_when_registered_and_identified_grants_desired_prefix(): void
    {
        $channel = $this->createMock(\App\Domain\ChanServ\Entity\RegisteredChannel::class);
        $channel->method('getName')->willReturn('#test');
        $channel->method('getId')->willReturn(self::CHANNEL_ID);
        $channel->method('isSecure')->willReturn(false);
        $channel->method('isFounder')->with(self::NICK_ID)->willReturn(false);
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
        $modeSupport = $this->createMock(ChannelModeSupportInterface::class);
        $modeSupport->method('getSupportedPrefixModes')->willReturn(['q', 'a', 'o', 'h', 'v']);

        $this->channelRepository->method('findByChannelName')->with('#test')->willReturn($channel);
        $this->channelRepository->expects(self::once())->method('save')->with($channel);
        $this->userLookup->method('findByUid')->with('001USER')->willReturn($sender);
        $this->userLookup->method('findByNick')->with('001USER')->willReturn(null);
        $this->nickRepository->method('findByNick')->with('TestUser')->willReturn($account);
        $this->accessRepository->method('findByChannelAndNick')->with(self::CHANNEL_ID, self::NICK_ID)->willReturn($accessStub);
        $this->levelRepository->method('findByChannelAndKey')->willReturnCallback(
            fn (int $channelId, string $key): ?ChannelLevel => match ($key) {
                ChannelLevel::KEY_AUTOADMIN => new ChannelLevel($channelId, $key, 200),
                ChannelLevel::KEY_AUTOOP => new ChannelLevel($channelId, $key, 100),
                default => null,
            },
        );
        $this->modeSupportProvider->method('getSupport')->willReturn($modeSupport);
        $this->channelServiceActions->expects(self::once())->method('setChannelMemberMode')->with('#test', '001USER', 'o', true);

        $event = new UserJoinedChannelEvent(
            uid: new Uid('001USER'),
            channel: new ChannelName('#test'),
            role: ChannelMemberRole::None,
        );
        $this->subscriber->onUserJoinedChannel($event);
    }

    #[Test]
    public function onMessageReceived_snapshots_pending_registry(): void
    {
        $this->syncPendingRegistry->add('#test');
        $this->subscriber->onMessageReceived(new MessageReceivedEvent(new IRCMessage('PING')));
        self::assertSame(['#test'], $this->syncPendingRegistry->getPendingAtStart());
    }

    #[Test]
    public function onIrcMessageProcessed_syncs_pending_channels_and_removes_them(): void
    {
        $this->syncPendingRegistry->add('#test');
        $this->syncPendingRegistry->snapshotPendingAtStart();

        $channel = $this->createMock(\App\Domain\ChanServ\Entity\RegisteredChannel::class);
        $channel->method('getName')->willReturn('#test');
        $channel->method('getId')->willReturn(self::CHANNEL_ID);
        $channel->method('isSecure')->willReturn(false);
        $view = new ChannelView('#test', '+nt', null, 0, []);

        $this->channelRepository->method('findByChannelName')->with('#test')->willReturn($channel);
        $this->channelLookup->method('findByChannelName')->with('#test')->willReturn($view);
        $this->channelServiceActions->expects(self::never())->method('setChannelModes');

        $this->subscriber->onIrcMessageProcessed(new IrcMessageProcessedEvent());

        self::assertSame([], $this->syncPendingRegistry->getPendingAtStart());
    }
}
