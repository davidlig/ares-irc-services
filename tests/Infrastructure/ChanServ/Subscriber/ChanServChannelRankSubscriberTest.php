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
use App\Domain\IRC\Event\ModeReceivedEvent;
use App\Domain\IRC\Event\NetworkSyncCompleteEvent;
use App\Domain\IRC\Event\UserJoinedChannelEvent;
use App\Domain\IRC\Event\UserLeftChannelEvent;
use App\Domain\IRC\Network\ChannelMemberRole;
use App\Domain\IRC\ValueObject\ChannelName;
use App\Domain\IRC\ValueObject\Uid;
use App\Domain\NickServ\Repository\RegisteredNickRepositoryInterface;
use App\Infrastructure\ChanServ\ChannelRankSyncPendingRegistry;
use App\Infrastructure\ChanServ\Subscriber\ChanServChannelRankSubscriber;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function count;
use function sprintf;

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
                UserLeftChannelEvent::class => ['onUserLeftChannel', 0],
                NetworkSyncCompleteEvent::class => ['onSyncComplete', 0],
                ChannelSecureEnabledEvent::class => ['onChannelSecureEnabled', 0],
                ChannelFounderChangedEvent::class => ['onChannelFounderChanged', 0],
                ModeReceivedEvent::class => ['onModeReceived', 255],
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
        $channel->method('isSecure')->willReturn(true);
        $channel->expects(self::atLeastOnce())->method('isFounder')->with(self::NICK_ID)->willReturn(false);

        $view = new ChannelView(
            name: '#test',
            modes: '+nt',
            topic: null,
            memberCount: 1,
            members: [
                ['uid' => '001USER', 'roleLetter' => 'v', 'prefixLetters' => ['v']],
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
            isIdentified: false,
        );
        $account = $this->createStub(\App\Domain\NickServ\Entity\RegisteredNick::class);
        $account->method('getId')->willReturn(self::NICK_ID);
        $accessStub = $this->createStub(\App\Domain\ChanServ\Entity\ChannelAccess::class);
        $accessStub->method('getLevel')->willReturn(10);

        $this->channelRepository = $this->createMock(RegisteredChannelRepositoryInterface::class);
        $this->channelRepository->expects(self::atLeastOnce())->method('listAll')->willReturn([$channel]);
        $this->channelLookup = $this->createMock(ChannelLookupPort::class);
        $this->channelLookup->expects(self::atLeastOnce())->method('findByChannelName')->with('#test')->willReturn($view);
        $this->modeSupportProvider = $this->createMock(ActiveChannelModeSupportProviderInterface::class);
        $this->modeSupportProvider->expects(self::atLeastOnce())->method('getSupport')->willReturn($modeSupport);
        $this->userLookup = $this->createMock(NetworkUserLookupPort::class);
        $this->userLookup->expects(self::atLeastOnce())->method('findByUid')->with('001USER')->willReturn($sender);
        $this->nickRepository = $this->createMock(RegisteredNickRepositoryInterface::class);
        $this->nickRepository->expects(self::atLeastOnce())->method('findByNick')->with('TestUser')->willReturn($account);
        $this->accessRepository = $this->createMock(ChannelAccessRepositoryInterface::class);
        $this->accessRepository->expects(self::atLeastOnce())->method('findByChannelAndNick')->with(self::CHANNEL_ID, self::NICK_ID)->willReturn($accessStub);
        $this->levelRepository = $this->createMock(ChannelLevelRepositoryInterface::class);
        $this->levelRepository->expects(self::atLeastOnce())->method('findByChannelAndKey')->willReturnCallback(
            static fn (int $channelId, string $key): ?ChannelLevel => match ($key) {
                ChannelLevel::KEY_AUTOVOICE => new ChannelLevel($channelId, $key, 10),
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
    public function syncWhenDesiredRankAboveCurrentGrantsMode(): void
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
                ['uid' => '001USER', 'roleLetter' => 'v', 'prefixLetters' => ['v']],
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
    public function onModeReceivedWithListModeB(): void
    {
        $channel = $this->createMock(\App\Domain\ChanServ\Entity\RegisteredChannel::class);
        $channel->expects(self::atLeastOnce())->method('isSecure')->willReturn(true);
        $channel->expects(self::atLeastOnce())->method('getId')->willReturn(self::CHANNEL_ID);

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
        $accessStub->method('getLevel')->willReturn(50);
        $modeSupport = $this->createStub(ChannelModeSupportInterface::class);
        $modeSupport->method('getSupportedPrefixModes')->willReturn(['q', 'a', 'o', 'h', 'v']);

        $this->channelRepository = $this->createMock(RegisteredChannelRepositoryInterface::class);
        $this->channelRepository->expects(self::atLeastOnce())->method('findByChannelName')->with('#test')->willReturn($channel);
        $this->userLookup = $this->createMock(NetworkUserLookupPort::class);
        $this->userLookup->expects(self::atLeastOnce())->method('findByUid')->with('001USER')->willReturn($sender);
        $this->nickRepository = $this->createMock(RegisteredNickRepositoryInterface::class);
        $this->nickRepository->expects(self::atLeastOnce())->method('findByNick')->with('TestUser')->willReturn($account);
        $this->accessRepository = $this->createMock(ChannelAccessRepositoryInterface::class);
        $this->accessRepository->expects(self::atLeastOnce())->method('findByChannelAndNick')->with(self::CHANNEL_ID, self::NICK_ID)->willReturn($accessStub);
        $this->levelRepository = $this->createMock(ChannelLevelRepositoryInterface::class);
        $this->levelRepository->expects(self::atLeastOnce())->method('findByChannelAndKey')->willReturnCallback(
            static fn (int $channelId, string $key): ?ChannelLevel => match ($key) {
                ChannelLevel::KEY_AUTOOP => new ChannelLevel($channelId, $key, 100),
                default => null,
            },
        );
        $this->modeSupportProvider = $this->createStub(ActiveChannelModeSupportProviderInterface::class);
        $this->modeSupportProvider->method('getSupport')->willReturn($modeSupport);
        $this->channelServiceActions = $this->createMock(ChannelServiceActionsPort::class);
        $this->channelServiceActions->expects(self::once())->method('setChannelMemberMode')->with('#test', '001USER', 'o', false);
        $this->rebuildSubscriber();

        $event = new ModeReceivedEvent(
            channelName: new ChannelName('#test'),
            modeStr: '+bo',
            modeParams: ['*!*@banned.host', '001USER'],
        );
        $this->subscriber->onModeReceived($event);
    }

    #[Test]
    public function onModeReceivedRemovingMode(): void
    {
        $channel = $this->createMock(\App\Domain\ChanServ\Entity\RegisteredChannel::class);
        $channel->expects(self::atLeastOnce())->method('isSecure')->willReturn(true);

        $this->channelRepository = $this->createMock(RegisteredChannelRepositoryInterface::class);
        $this->channelRepository->expects(self::atLeastOnce())->method('findByChannelName')->with('#test')->willReturn($channel);
        $this->channelServiceActions = $this->createMock(ChannelServiceActionsPort::class);
        $this->channelServiceActions->expects(self::never())->method('setChannelMemberMode');
        $this->rebuildSubscriber();

        $event = new ModeReceivedEvent(
            channelName: new ChannelName('#test'),
            modeStr: '-o',
            modeParams: ['001USER'],
        );
        $this->subscriber->onModeReceived($event);
    }

    #[Test]
    public function resolveMemberContextWithFindByNickFallback(): void
    {
        $channel = $this->createMock(\App\Domain\ChanServ\Entity\RegisteredChannel::class);
        $channel->expects(self::atLeastOnce())->method('isSecure')->willReturn(true);
        $channel->expects(self::atLeastOnce())->method('getId')->willReturn(self::CHANNEL_ID);

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
        $this->userLookup = $this->createMock(NetworkUserLookupPort::class);
        $this->userLookup->expects(self::once())->method('findByUid')->with('CannotFindByUid')->willReturn(null);
        $this->userLookup->expects(self::once())->method('findByNick')->with('CannotFindByUid')->willReturn($sender);
        $this->nickRepository = $this->createMock(RegisteredNickRepositoryInterface::class);
        $this->nickRepository->expects(self::atLeastOnce())->method('findByNick')->with('TestUser')->willReturn($account);
        $this->accessRepository = $this->createMock(ChannelAccessRepositoryInterface::class);
        $this->accessRepository->expects(self::atLeastOnce())->method('findByChannelAndNick')->with(self::CHANNEL_ID, self::NICK_ID)->willReturn($accessStub);
        $this->levelRepository = $this->createMock(ChannelLevelRepositoryInterface::class);
        $this->levelRepository->expects(self::atLeastOnce())->method('findByChannelAndKey')->willReturnCallback(
            static fn (int $channelId, string $key): ?ChannelLevel => match ($key) {
                ChannelLevel::KEY_AUTOOP => new ChannelLevel($channelId, $key, 100),
                default => null,
            },
        );
        $this->modeSupportProvider = $this->createStub(ActiveChannelModeSupportProviderInterface::class);
        $this->modeSupportProvider->method('getSupport')->willReturn($modeSupport);
        $this->channelServiceActions = $this->createMock(ChannelServiceActionsPort::class);
        $this->channelServiceActions->expects(self::never())->method('setChannelMemberMode');
        $this->rebuildSubscriber();

        $event = new ModeReceivedEvent(
            channelName: new ChannelName('#test'),
            modeStr: '+o',
            modeParams: ['CannotFindByUid'],
        );
        $this->subscriber->onModeReceived($event);
    }

    #[Test]
    public function onUserLeftChannelUpdatesLastUsed(): void
    {
        $channel = $this->createMock(\App\Domain\ChanServ\Entity\RegisteredChannel::class);
        $channel->expects(self::atLeastOnce())->method('getId')->willReturn(self::CHANNEL_ID);
        $channel->expects(self::never())->method('isSecure');
        $channel->expects(self::once())->method('touchLastUsed');

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
        $this->nickRepository = $this->createMock(RegisteredNickRepositoryInterface::class);
        $this->nickRepository->expects(self::atLeastOnce())->method('findByNick')->with('TestUser')->willReturn($account);
        $this->accessRepository = $this->createMock(ChannelAccessRepositoryInterface::class);
        $this->accessRepository->expects(self::atLeastOnce())->method('findByChannelAndNick')->with(self::CHANNEL_ID, self::NICK_ID)->willReturn($accessStub);
        $this->levelRepository = $this->createMock(ChannelLevelRepositoryInterface::class);
        $this->levelRepository->expects(self::atLeastOnce())->method('findByChannelAndKey')->willReturnCallback(
            static fn (int $channelId, string $key): ?ChannelLevel => match ($key) {
                ChannelLevel::KEY_AUTOOP => new ChannelLevel($channelId, $key, 100),
                default => null,
            },
        );
        $this->modeSupportProvider = $this->createStub(ActiveChannelModeSupportProviderInterface::class);
        $this->modeSupportProvider->method('getSupport')->willReturn($modeSupport);
        $this->channelServiceActions = $this->createMock(ChannelServiceActionsPort::class);
        $this->channelServiceActions->expects(self::never())->method('setChannelMemberMode');
        $this->rebuildSubscriber();

        $event = new UserLeftChannelEvent(
            uid: new Uid('001USER'),
            nick: new \App\Domain\IRC\ValueObject\Nick('TestUser'),
            channel: new ChannelName('#test'),
            reason: '',
            wasKicked: false,
        );
        $this->subscriber->onUserLeftChannel($event);
    }

    #[Test]
    public function onUserJoinedChannelWithHasRoleNoSecureIdentified(): void
    {
        $channel = $this->createMock(\App\Domain\ChanServ\Entity\RegisteredChannel::class);
        $channel->expects(self::atLeastOnce())->method('getId')->willReturn(self::CHANNEL_ID);
        $channel->expects(self::atLeastOnce())->method('isSecure')->willReturn(false);
        $channel->expects(self::atLeastOnce())->method('isFounder')->with(self::NICK_ID)->willReturn(false);
        $channel->expects(self::once())->method('touchLastUsed');
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
        $this->nickRepository = $this->createMock(RegisteredNickRepositoryInterface::class);
        $this->nickRepository->expects(self::atLeastOnce())->method('findByNick')->with('TestUser')->willReturn($account);
        $this->accessRepository = $this->createMock(ChannelAccessRepositoryInterface::class);
        $this->accessRepository->expects(self::atLeastOnce())->method('findByChannelAndNick')->with(self::CHANNEL_ID, self::NICK_ID)->willReturn($accessStub);
        $this->levelRepository = $this->createMock(ChannelLevelRepositoryInterface::class);
        $this->levelRepository->expects(self::atLeastOnce())->method('findByChannelAndKey')->willReturnCallback(
            static fn (int $channelId, string $key): ?ChannelLevel => match ($key) {
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
    public function onUserJoinedChannelWithNoRoleSecureNoAccess(): void
    {
        $channel = $this->createMock(\App\Domain\ChanServ\Entity\RegisteredChannel::class);
        $channel->expects(self::atLeastOnce())->method('getId')->willReturn(self::CHANNEL_ID);
        $channel->expects(self::atLeastOnce())->method('isSecure')->willReturn(true);
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
        $accessStub->method('getLevel')->willReturn(10);
        $modeSupport = $this->createStub(ChannelModeSupportInterface::class);
        $modeSupport->method('getSupportedPrefixModes')->willReturn(['q', 'a', 'o', 'h', 'v']);

        $this->channelRepository = $this->createMock(RegisteredChannelRepositoryInterface::class);
        $this->channelRepository->expects(self::atLeastOnce())->method('findByChannelName')->with('#test')->willReturn($channel);
        $this->channelRepository->expects(self::never())->method('save');
        $this->userLookup = $this->createMock(NetworkUserLookupPort::class);
        $this->userLookup->expects(self::atLeastOnce())->method('findByUid')->with('001USER')->willReturn($sender);
        $this->nickRepository = $this->createMock(RegisteredNickRepositoryInterface::class);
        $this->nickRepository->expects(self::atLeastOnce())->method('findByNick')->with('TestUser')->willReturn($account);
        $this->accessRepository = $this->createMock(ChannelAccessRepositoryInterface::class);
        $this->accessRepository->expects(self::atLeastOnce())->method('findByChannelAndNick')->with(self::CHANNEL_ID, self::NICK_ID)->willReturn($accessStub);
        $this->levelRepository = $this->createMock(ChannelLevelRepositoryInterface::class);
        $this->levelRepository->expects(self::atLeastOnce())->method('findByChannelAndKey')->willReturnCallback(
            static fn (int $channelId, string $key): ?ChannelLevel => match ($key) {
                ChannelLevel::KEY_AUTOVOICE => new ChannelLevel($channelId, $key, 30),
                default => null,
            },
        );
        $this->modeSupportProvider = $this->createStub(ActiveChannelModeSupportProviderInterface::class);
        $this->modeSupportProvider->method('getSupport')->willReturn($modeSupport);
        $this->channelServiceActions = $this->createMock(ChannelServiceActionsPort::class);
        $this->channelServiceActions->expects(self::exactly(2))->method('setChannelMemberMode');
        $this->rebuildSubscriber();

        $event = new UserJoinedChannelEvent(
            uid: new Uid('001USER'),
            channel: new ChannelName('#test'),
            role: ChannelMemberRole::Voice,
        );
        $this->subscriber->onUserJoinedChannel($event);
    }

    #[Test]
    public function syncRanksForChannelWithFounderCheck(): void
    {
        $channel = $this->createMock(\App\Domain\ChanServ\Entity\RegisteredChannel::class);
        $channel->expects(self::atLeastOnce())->method('getName')->willReturn('#test');
        $channel->expects(self::atLeastOnce())->method('isSecure')->willReturn(false);
        $channel->expects(self::atLeastOnce())->method('isFounder')->with(self::NICK_ID)->willReturn(true);

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

        $this->channelRepository = $this->createMock(RegisteredChannelRepositoryInterface::class);
        $this->channelRepository->expects(self::atLeastOnce())->method('listAll')->willReturn([$channel]);
        $this->channelLookup = $this->createMock(ChannelLookupPort::class);
        $this->channelLookup->expects(self::atLeastOnce())->method('findByChannelName')->with('#test')->willReturn($view);
        $this->modeSupportProvider = $this->createMock(ActiveChannelModeSupportProviderInterface::class);
        $this->modeSupportProvider->expects(self::atLeastOnce())->method('getSupport')->willReturn($modeSupport);
        $this->userLookup = $this->createMock(NetworkUserLookupPort::class);
        $this->userLookup->expects(self::atLeastOnce())->method('findByUid')->with('001USER')->willReturn($sender);
        $this->nickRepository = $this->createMock(RegisteredNickRepositoryInterface::class);
        $this->nickRepository->expects(self::atLeastOnce())->method('findByNick')->with('TestUser')->willReturn($account);
        $this->accessRepository = $this->createMock(ChannelAccessRepositoryInterface::class);
        $this->accessRepository->expects(self::never())->method('findByChannelAndNick');
        $this->levelRepository = $this->createMock(ChannelLevelRepositoryInterface::class);
        $this->levelRepository->expects(self::never())->method('findByChannelAndKey');
        $this->channelServiceActions = $this->createMock(ChannelServiceActionsPort::class);
        $this->channelServiceActions->expects(self::once())->method('setChannelModes')->with('#test', '+q', ['001USER']);
        $this->rebuildSubscriber();

        $connection = $this->createStub(ConnectionInterface::class);
        $this->subscriber->onSyncComplete(new NetworkSyncCompleteEvent($connection, '001'));
    }

    #[Test]
    public function syncRanksForChannelWithUnauthenticatedUser(): void
    {
        $channel = $this->createMock(\App\Domain\ChanServ\Entity\RegisteredChannel::class);
        $channel->expects(self::atLeastOnce())->method('getName')->willReturn('#test');
        $channel->expects(self::atLeastOnce())->method('getId')->willReturn(self::CHANNEL_ID);
        $channel->method('isSecure')->willReturn(false);

        $view = new ChannelView(
            name: '#test',
            modes: '+nt',
            topic: null,
            memberCount: 1,
            members: [
                ['uid' => '001USER', 'roleLetter' => 'o', 'prefixLetters' => ['o']],
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
            isIdentified: false,
        );
        $account = $this->createStub(\App\Domain\NickServ\Entity\RegisteredNick::class);
        $account->method('getId')->willReturn(self::NICK_ID);

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
        $this->channelServiceActions = $this->createMock(ChannelServiceActionsPort::class);
        $this->channelServiceActions->expects(self::once())->method('setChannelModes')->with('#test', '-o', ['001USER']);
        $this->rebuildSubscriber();

        $connection = $this->createStub(ConnectionInterface::class);
        $this->subscriber->onSyncComplete(new NetworkSyncCompleteEvent($connection, '001'));
    }

    #[Test]
    public function collectOpsWhenRankAboveDesiredMultiplePrefixes(): void
    {
        $channel = $this->createMock(\App\Domain\ChanServ\Entity\RegisteredChannel::class);
        $channel->expects(self::atLeastOnce())->method('getName')->willReturn('#test');
        $channel->expects(self::atLeastOnce())->method('getId')->willReturn(self::CHANNEL_ID);
        $channel->method('isSecure')->willReturn(false);

        $view = new ChannelView(
            name: '#test',
            modes: '+nt',
            topic: null,
            memberCount: 1,
            members: [
                ['uid' => '001USER', 'roleLetter' => 'q', 'prefixLetters' => ['q', 'a', 'o']],
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
        $accessStub->method('getLevel')->willReturn(30);

        $this->channelRepository = $this->createMock(RegisteredChannelRepositoryInterface::class);
        $this->channelRepository->expects(self::atLeastOnce())->method('listAll')->willReturn([$channel]);
        $this->channelLookup = $this->createMock(ChannelLookupPort::class);
        $this->channelLookup->expects(self::atLeastOnce())->method('findByChannelName')->with('#test')->willReturn($view);
        $this->modeSupportProvider = $this->createMock(ActiveChannelModeSupportProviderInterface::class);
        $this->modeSupportProvider->expects(self::atLeastOnce())->method('getSupport')->willReturn($modeSupport);
        $this->userLookup = $this->createMock(NetworkUserLookupPort::class);
        $this->userLookup->expects(self::atLeastOnce())->method('findByUid')->with('001USER')->willReturn($sender);
        $this->nickRepository = $this->createMock(RegisteredNickRepositoryInterface::class);
        $this->nickRepository->expects(self::atLeastOnce())->method('findByNick')->with('TestUser')->willReturn($account);
        $this->accessRepository = $this->createMock(ChannelAccessRepositoryInterface::class);
        $this->accessRepository->expects(self::atLeastOnce())->method('findByChannelAndNick')->with(self::CHANNEL_ID, self::NICK_ID)->willReturn($accessStub);
        $this->levelRepository = $this->createMock(ChannelLevelRepositoryInterface::class);
        $this->levelRepository->expects(self::atLeastOnce())->method('findByChannelAndKey')->willReturnCallback(
            static fn (int $channelId, string $key): ?ChannelLevel => match ($key) {
                ChannelLevel::KEY_AUTOVOICE => new ChannelLevel($channelId, $key, 5),
                default => null,
            },
        );
        $this->channelServiceActions = $this->createMock(ChannelServiceActionsPort::class);
        $this->channelServiceActions->expects(self::once())->method('setChannelModes')->with('#test', '-qao+v', ['001USER', '001USER', '001USER', '001USER']);
        $this->rebuildSubscriber();

        $connection = $this->createStub(ConnectionInterface::class);
        $this->subscriber->onSyncComplete(new NetworkSyncCompleteEvent($connection, '001'));
    }

    #[Test]
    public function collectOpsForSecureStripMultiplePrefixes(): void
    {
        $channel = $this->createMock(\App\Domain\ChanServ\Entity\RegisteredChannel::class);
        $channel->expects(self::atLeastOnce())->method('getName')->willReturn('#test');
        $channel->method('isSecure')->willReturn(true);

        $view = new ChannelView(
            name: '#test',
            modes: '+nt',
            topic: null,
            memberCount: 1,
            members: [
                ['uid' => '001USER', 'roleLetter' => 'q', 'prefixLetters' => ['q', 'a', 'o', 'h', 'v']],
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
            isIdentified: false,
        );

        $this->channelRepository = $this->createMock(RegisteredChannelRepositoryInterface::class);
        $this->channelRepository->expects(self::atLeastOnce())->method('listAll')->willReturn([$channel]);
        $this->channelLookup = $this->createMock(ChannelLookupPort::class);
        $this->channelLookup->expects(self::atLeastOnce())->method('findByChannelName')->with('#test')->willReturn($view);
        $this->modeSupportProvider = $this->createMock(ActiveChannelModeSupportProviderInterface::class);
        $this->modeSupportProvider->expects(self::atLeastOnce())->method('getSupport')->willReturn($modeSupport);
        $this->userLookup = $this->createMock(NetworkUserLookupPort::class);
        $this->userLookup->expects(self::atLeastOnce())->method('findByUid')->with('001USER')->willReturn($sender);
        $this->nickRepository = $this->createMock(RegisteredNickRepositoryInterface::class);
        $this->nickRepository->expects(self::atLeastOnce())->method('findByNick')->with('TestUser')->willReturn(null);
        $this->channelServiceActions = $this->createMock(ChannelServiceActionsPort::class);
        $this->channelServiceActions->expects(self::once())->method('setChannelModes')->with('#test', '-qaohv', ['001USER', '001USER', '001USER', '001USER', '001USER']);
        $this->rebuildSubscriber();

        $connection = $this->createStub(ConnectionInterface::class);
        $this->subscriber->onSyncComplete(new NetworkSyncCompleteEvent($connection, '001'));
    }

    #[Test]
    public function flushMemberModeBatchWithMoreThanSixOps(): void
    {
        $members = [];
        for ($i = 1; $i <= 8; ++$i) {
            $members[] = ['uid' => sprintf('001USER%d', $i), 'roleLetter' => '', 'prefixLetters' => []];
        }

        $channel = $this->createMock(\App\Domain\ChanServ\Entity\RegisteredChannel::class);
        $channel->expects(self::atLeastOnce())->method('getName')->willReturn('#test');
        $channel->expects(self::atLeastOnce())->method('getId')->willReturn(self::CHANNEL_ID);
        $channel->method('isSecure')->willReturn(false);

        $view = new ChannelView(
            name: '#test',
            modes: '+nt',
            topic: null,
            memberCount: 8,
            members: $members,
        );
        $modeSupport = $this->createStub(ChannelModeSupportInterface::class);
        $modeSupport->method('getSupportedPrefixModes')->willReturn(['q', 'a', 'o', 'h', 'v']);

        $sender = new SenderView(
            uid: '001USER1',
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
        $this->userLookup->expects(self::exactly(8))->method('findByUid')->willReturn($sender);
        $this->nickRepository = $this->createMock(RegisteredNickRepositoryInterface::class);
        $this->nickRepository->expects(self::exactly(8))->method('findByNick')->willReturn($account);
        $this->accessRepository = $this->createMock(ChannelAccessRepositoryInterface::class);
        $this->accessRepository->expects(self::atLeastOnce())->method('findByChannelAndNick')->willReturn($accessStub);
        $this->levelRepository = $this->createMock(ChannelLevelRepositoryInterface::class);
        $this->levelRepository->expects(self::atLeastOnce())->method('findByChannelAndKey')->willReturnCallback(
            static fn (int $channelId, string $key): ?ChannelLevel => match ($key) {
                ChannelLevel::KEY_AUTOOP => new ChannelLevel($channelId, $key, 100),
                default => null,
            },
        );
        $this->channelServiceActions = $this->createMock(ChannelServiceActionsPort::class);
        $this->channelServiceActions->expects(self::exactly(2))->method('setChannelModes');
        $this->channelServiceActions->expects(self::exactly(2))->method('setChannelModes')->with(
            '#test',
            self::callback(static fn (string $modeStr): bool => '+oooooo' === $modeStr || '+oo' === $modeStr),
            self::callback(static fn (array $params): bool => 6 === count($params) || 2 === count($params)),
        );
        $this->rebuildSubscriber();

        $connection = $this->createStub(ConnectionInterface::class);
        $this->subscriber->onSyncComplete(new NetworkSyncCompleteEvent($connection, '001'));
    }

    #[Test]
    public function onUserJoinedChannelWhenChannelNotFoundDoesNothing(): void
    {
        $this->channelRepository = $this->createMock(RegisteredChannelRepositoryInterface::class);
        $this->channelRepository->expects(self::once())->method('findByChannelName')->with('#test')->willReturn(null);
        $this->channelServiceActions = $this->createMock(ChannelServiceActionsPort::class);
        $this->channelServiceActions->expects(self::never())->method('setChannelMemberMode');
        $this->rebuildSubscriber();

        $event = new UserJoinedChannelEvent(
            uid: new Uid('001USER'),
            channel: new ChannelName('#test'),
            role: ChannelMemberRole::Op,
        );
        $this->subscriber->onUserJoinedChannel($event);
    }

    #[Test]
    public function onUserJoinedChannelWhenUserNotInNetworkDoesNothing(): void
    {
        $this->channelRepository = $this->createMock(RegisteredChannelRepositoryInterface::class);
        $this->channelRepository->expects(self::once())->method('findByChannelName')->with('#test')->willReturn($this->createStub(\App\Domain\ChanServ\Entity\RegisteredChannel::class));
        $this->userLookup = $this->createMock(NetworkUserLookupPort::class);
        $this->userLookup->expects(self::once())->method('findByUid')->with('001USER')->willReturn(null);
        $this->userLookup->expects(self::once())->method('findByNick')->with('001USER')->willReturn(null);
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
    public function onUserJoinedChannelWhenDesiredEmptyDoesNothing(): void
    {
        $channel = $this->createMock(\App\Domain\ChanServ\Entity\RegisteredChannel::class);
        $channel->method('getId')->willReturn(self::CHANNEL_ID);
        $channel->method('isSecure')->willReturn(false);
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
        $modeSupport = $this->createStub(ChannelModeSupportInterface::class);
        $modeSupport->method('getSupportedPrefixModes')->willReturn(['q', 'a', 'o', 'h', 'v']);

        $this->channelRepository = $this->createMock(RegisteredChannelRepositoryInterface::class);
        $this->channelRepository->expects(self::once())->method('findByChannelName')->with('#test')->willReturn($channel);
        $this->userLookup = $this->createMock(NetworkUserLookupPort::class);
        $this->userLookup->expects(self::once())->method('findByUid')->with('001USER')->willReturn($sender);
        $this->nickRepository = $this->createMock(RegisteredNickRepositoryInterface::class);
        $this->nickRepository->expects(self::once())->method('findByNick')->with('TestUser')->willReturn($account);
        $this->accessRepository = $this->createMock(ChannelAccessRepositoryInterface::class);
        $this->accessRepository->expects(self::once())->method('findByChannelAndNick')->with(self::CHANNEL_ID, self::NICK_ID)->willReturn(null);
        $this->modeSupportProvider = $this->createStub(ActiveChannelModeSupportProviderInterface::class);
        $this->modeSupportProvider->method('getSupport')->willReturn($modeSupport);
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
    public function onUserJoinedChannelWhenNotIdentifiedDoesNothing(): void
    {
        $channel = $this->createMock(\App\Domain\ChanServ\Entity\RegisteredChannel::class);
        $channel->method('getId')->willReturn(self::CHANNEL_ID);
        $channel->method('isSecure')->willReturn(false);
        $channel->expects(self::atLeastOnce())->method('isFounder')->with(self::NICK_ID)->willReturn(false);

        $sender = new SenderView(
            uid: '001USER',
            nick: 'TestUser',
            ident: '~u',
            hostname: 'user.example.com',
            cloakedHost: 'user.example.com',
            ipBase64: '',
            isIdentified: false,
        );
        $account = $this->createStub(\App\Domain\NickServ\Entity\RegisteredNick::class);
        $account->method('getId')->willReturn(self::NICK_ID);
        $accessStub = $this->createStub(\App\Domain\ChanServ\Entity\ChannelAccess::class);
        $accessStub->method('getLevel')->willReturn(100);
        $modeSupport = $this->createStub(ChannelModeSupportInterface::class);
        $modeSupport->method('getSupportedPrefixModes')->willReturn(['q', 'a', 'o', 'h', 'v']);

        $this->channelRepository = $this->createMock(RegisteredChannelRepositoryInterface::class);
        $this->channelRepository->expects(self::once())->method('findByChannelName')->with('#test')->willReturn($channel);
        $this->userLookup = $this->createMock(NetworkUserLookupPort::class);
        $this->userLookup->expects(self::once())->method('findByUid')->with('001USER')->willReturn($sender);
        $this->nickRepository = $this->createMock(RegisteredNickRepositoryInterface::class);
        $this->nickRepository->expects(self::once())->method('findByNick')->with('TestUser')->willReturn($account);
        $this->accessRepository = $this->createMock(ChannelAccessRepositoryInterface::class);
        $this->accessRepository->expects(self::once())->method('findByChannelAndNick')->with(self::CHANNEL_ID, self::NICK_ID)->willReturn($accessStub);
        $this->levelRepository = $this->createStub(ChannelLevelRepositoryInterface::class);
        $this->levelRepository->method('findByChannelAndKey')->willReturnCallback(
            static fn (int $channelId, string $key): ?ChannelLevel => match ($key) {
                ChannelLevel::KEY_AUTOOP => new ChannelLevel($channelId, $key, 100),
                default => null,
            },
        );
        $this->modeSupportProvider = $this->createStub(ActiveChannelModeSupportProviderInterface::class);
        $this->modeSupportProvider->method('getSupport')->willReturn($modeSupport);
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
    public function onUserJoinedChannelSecureStripAboveDesired(): void
    {
        $channel = $this->createMock(\App\Domain\ChanServ\Entity\RegisteredChannel::class);
        $channel->method('getId')->willReturn(self::CHANNEL_ID);
        $channel->method('isSecure')->willReturn(true);
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
        $accessStub->method('getLevel')->willReturn(30);
        $modeSupport = $this->createStub(ChannelModeSupportInterface::class);
        $modeSupport->method('getSupportedPrefixModes')->willReturn(['q', 'a', 'o', 'h', 'v']);

        $this->channelRepository = $this->createMock(RegisteredChannelRepositoryInterface::class);
        $this->channelRepository->expects(self::once())->method('findByChannelName')->with('#test')->willReturn($channel);
        $this->userLookup = $this->createMock(NetworkUserLookupPort::class);
        $this->userLookup->expects(self::once())->method('findByUid')->with('001USER')->willReturn($sender);
        $this->nickRepository = $this->createMock(RegisteredNickRepositoryInterface::class);
        $this->nickRepository->expects(self::once())->method('findByNick')->with('TestUser')->willReturn($account);
        $this->accessRepository = $this->createMock(ChannelAccessRepositoryInterface::class);
        $this->accessRepository->expects(self::once())->method('findByChannelAndNick')->with(self::CHANNEL_ID, self::NICK_ID)->willReturn($accessStub);
        $this->levelRepository = $this->createStub(ChannelLevelRepositoryInterface::class);
        $this->levelRepository->method('findByChannelAndKey')->willReturnCallback(
            static fn (int $channelId, string $key): ?ChannelLevel => match ($key) {
                ChannelLevel::KEY_AUTOVOICE => new ChannelLevel($channelId, $key, 30),
                default => null,
            },
        );
        $this->modeSupportProvider = $this->createStub(ActiveChannelModeSupportProviderInterface::class);
        $this->modeSupportProvider->method('getSupport')->willReturn($modeSupport);
        $this->channelServiceActions = $this->createMock(ChannelServiceActionsPort::class);
        $this->channelServiceActions->expects(self::exactly(2))->method('setChannelMemberMode');
        $this->channelServiceActions->expects(self::exactly(2))->method('setChannelMemberMode')->willReturnCallback(static function (string $channel, string $uid, string $mode, bool $add): void {
            static $calls = 0;
            ++$calls;
            if (1 === $calls) {
                self::assertSame('o', $mode);
                self::assertFalse($add);
            } else {
                self::assertSame('v', $mode);
                self::assertTrue($add);
            }
        });
        $this->rebuildSubscriber();

        $event = new UserJoinedChannelEvent(
            uid: new Uid('001USER'),
            channel: new ChannelName('#test'),
            role: ChannelMemberRole::Op,
        );
        $this->subscriber->onUserJoinedChannel($event);
    }

    #[Test]
    public function onUserLeftChannelWhenChannelNotFoundDoesNothing(): void
    {
        $this->channelRepository = $this->createMock(RegisteredChannelRepositoryInterface::class);
        $this->channelRepository->expects(self::once())->method('findByChannelName')->with('#test')->willReturn(null);
        $this->channelServiceActions = $this->createMock(ChannelServiceActionsPort::class);
        $this->channelServiceActions->expects(self::never())->method('setChannelMemberMode');
        $this->rebuildSubscriber();

        $event = new UserLeftChannelEvent(
            uid: new Uid('001USER'),
            nick: new \App\Domain\IRC\ValueObject\Nick('TestUser'),
            channel: new ChannelName('#test'),
            reason: '',
            wasKicked: false,
        );
        $this->subscriber->onUserLeftChannel($event);
    }

    #[Test]
    public function onUserLeftChannelWhenUserNotInNetworkDoesNothing(): void
    {
        $this->channelRepository = $this->createMock(RegisteredChannelRepositoryInterface::class);
        $this->channelRepository->expects(self::once())->method('findByChannelName')->with('#test')->willReturn($this->createStub(\App\Domain\ChanServ\Entity\RegisteredChannel::class));
        $this->userLookup = $this->createMock(NetworkUserLookupPort::class);
        $this->userLookup->expects(self::once())->method('findByUid')->with('001USER')->willReturn(null);
        $this->userLookup->expects(self::once())->method('findByNick')->with('001USER')->willReturn(null);
        $this->channelServiceActions = $this->createMock(ChannelServiceActionsPort::class);
        $this->channelServiceActions->expects(self::never())->method('setChannelMemberMode');
        $this->rebuildSubscriber();

        $event = new UserLeftChannelEvent(
            uid: new Uid('001USER'),
            nick: new \App\Domain\IRC\ValueObject\Nick('TestUser'),
            channel: new ChannelName('#test'),
            reason: '',
            wasKicked: false,
        );
        $this->subscriber->onUserLeftChannel($event);
    }

    #[Test]
    public function onUserLeftChannelWhenDesiredEmptyDoesNothing(): void
    {
        $channel = $this->createStub(\App\Domain\ChanServ\Entity\RegisteredChannel::class);
        $channel->method('getId')->willReturn(self::CHANNEL_ID);

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
        $modeSupport = $this->createStub(ChannelModeSupportInterface::class);
        $modeSupport->method('getSupportedPrefixModes')->willReturn(['q', 'a', 'o', 'h', 'v']);

        $this->channelRepository = $this->createMock(RegisteredChannelRepositoryInterface::class);
        $this->channelRepository->expects(self::once())->method('findByChannelName')->with('#test')->willReturn($channel);
        $this->userLookup = $this->createMock(NetworkUserLookupPort::class);
        $this->userLookup->expects(self::once())->method('findByUid')->with('001USER')->willReturn($sender);
        $this->nickRepository = $this->createMock(RegisteredNickRepositoryInterface::class);
        $this->nickRepository->expects(self::once())->method('findByNick')->with('TestUser')->willReturn($account);
        $this->accessRepository = $this->createMock(ChannelAccessRepositoryInterface::class);
        $this->accessRepository->expects(self::once())->method('findByChannelAndNick')->with(self::CHANNEL_ID, self::NICK_ID)->willReturn(null);
        $this->modeSupportProvider = $this->createStub(ActiveChannelModeSupportProviderInterface::class);
        $this->modeSupportProvider->method('getSupport')->willReturn($modeSupport);
        $this->channelServiceActions = $this->createMock(ChannelServiceActionsPort::class);
        $this->channelServiceActions->expects(self::never())->method('setChannelMemberMode');
        $this->rebuildSubscriber();

        $event = new UserLeftChannelEvent(
            uid: new Uid('001USER'),
            nick: new \App\Domain\IRC\ValueObject\Nick('TestUser'),
            channel: new ChannelName('#test'),
            reason: '',
            wasKicked: false,
        );
        $this->subscriber->onUserLeftChannel($event);
    }

    #[Test]
    public function onUserLeftChannelWhenNotIdentifiedDoesNothing(): void
    {
        $channel = $this->createStub(\App\Domain\ChanServ\Entity\RegisteredChannel::class);
        $channel->method('getId')->willReturn(self::CHANNEL_ID);

        $sender = new SenderView(
            uid: '001USER',
            nick: 'TestUser',
            ident: '~u',
            hostname: 'user.example.com',
            cloakedHost: 'user.example.com',
            ipBase64: '',
            isIdentified: false,
        );
        $account = $this->createStub(\App\Domain\NickServ\Entity\RegisteredNick::class);
        $account->method('getId')->willReturn(self::NICK_ID);
        $accessStub = $this->createStub(\App\Domain\ChanServ\Entity\ChannelAccess::class);
        $accessStub->method('getLevel')->willReturn(100);
        $modeSupport = $this->createStub(ChannelModeSupportInterface::class);
        $modeSupport->method('getSupportedPrefixModes')->willReturn(['q', 'a', 'o', 'h', 'v']);

        $this->channelRepository = $this->createMock(RegisteredChannelRepositoryInterface::class);
        $this->channelRepository->expects(self::once())->method('findByChannelName')->with('#test')->willReturn($channel);
        $this->userLookup = $this->createMock(NetworkUserLookupPort::class);
        $this->userLookup->expects(self::once())->method('findByUid')->with('001USER')->willReturn($sender);
        $this->nickRepository = $this->createMock(RegisteredNickRepositoryInterface::class);
        $this->nickRepository->expects(self::once())->method('findByNick')->with('TestUser')->willReturn($account);
        $this->accessRepository = $this->createMock(ChannelAccessRepositoryInterface::class);
        $this->accessRepository->expects(self::once())->method('findByChannelAndNick')->with(self::CHANNEL_ID, self::NICK_ID)->willReturn($accessStub);
        $this->levelRepository = $this->createStub(ChannelLevelRepositoryInterface::class);
        $this->levelRepository->method('findByChannelAndKey')->willReturnCallback(
            static fn (int $channelId, string $key): ?ChannelLevel => match ($key) {
                ChannelLevel::KEY_AUTOOP => new ChannelLevel($channelId, $key, 100),
                default => null,
            },
        );
        $this->modeSupportProvider = $this->createStub(ActiveChannelModeSupportProviderInterface::class);
        $this->modeSupportProvider->method('getSupport')->willReturn($modeSupport);
        $this->channelServiceActions = $this->createMock(ChannelServiceActionsPort::class);
        $this->channelServiceActions->expects(self::never())->method('setChannelMemberMode');
        $this->rebuildSubscriber();

        $event = new UserLeftChannelEvent(
            uid: new Uid('001USER'),
            nick: new \App\Domain\IRC\ValueObject\Nick('TestUser'),
            channel: new ChannelName('#test'),
            reason: '',
            wasKicked: false,
        );
        $this->subscriber->onUserLeftChannel($event);
    }

    #[Test]
    public function onModeReceivedWhenChannelNotFoundDoesNothing(): void
    {
        $this->channelRepository = $this->createMock(RegisteredChannelRepositoryInterface::class);
        $this->channelRepository->expects(self::once())->method('findByChannelName')->with('#test')->willReturn(null);
        $this->channelServiceActions = $this->createMock(ChannelServiceActionsPort::class);
        $this->channelServiceActions->expects(self::never())->method('setChannelMemberMode');
        $this->rebuildSubscriber();

        $event = new ModeReceivedEvent(
            channelName: new ChannelName('#test'),
            modeStr: '+o',
            modeParams: ['001USER'],
        );
        $this->subscriber->onModeReceived($event);
    }

    #[Test]
    public function onModeReceivedWhenChannelNotSecureDoesNothing(): void
    {
        $channel = $this->createMock(\App\Domain\ChanServ\Entity\RegisteredChannel::class);
        $channel->expects(self::once())->method('isSecure')->willReturn(false);

        $this->channelRepository = $this->createMock(RegisteredChannelRepositoryInterface::class);
        $this->channelRepository->expects(self::once())->method('findByChannelName')->with('#test')->willReturn($channel);
        $this->channelServiceActions = $this->createMock(ChannelServiceActionsPort::class);
        $this->channelServiceActions->expects(self::never())->method('setChannelMemberMode');
        $this->rebuildSubscriber();

        $event = new ModeReceivedEvent(
            channelName: new ChannelName('#test'),
            modeStr: '+o',
            modeParams: ['001USER'],
        );
        $this->subscriber->onModeReceived($event);
    }

    #[Test]
    public function onModeReceivedBreaksWhenParamIdxExceedsParams(): void
    {
        $channel = $this->createStub(\App\Domain\ChanServ\Entity\RegisteredChannel::class);
        $channel->method('isSecure')->willReturn(true);

        $this->channelRepository = $this->createMock(RegisteredChannelRepositoryInterface::class);
        $this->channelRepository->expects(self::once())->method('findByChannelName')->with('#test')->willReturn($channel);
        $this->channelServiceActions = $this->createMock(ChannelServiceActionsPort::class);
        $this->channelServiceActions->expects(self::never())->method('setChannelMemberMode');
        $this->rebuildSubscriber();

        $event = new ModeReceivedEvent(
            channelName: new ChannelName('#test'),
            modeStr: '+o',
            modeParams: [],
        );
        $this->subscriber->onModeReceived($event);
    }

    #[Test]
    public function onModeReceivedWithListModeE(): void
    {
        $channel = $this->createStub(\App\Domain\ChanServ\Entity\RegisteredChannel::class);
        $channel->method('isSecure')->willReturn(true);

        $this->channelRepository = $this->createMock(RegisteredChannelRepositoryInterface::class);
        $this->channelRepository->expects(self::once())->method('findByChannelName')->with('#test')->willReturn($channel);
        $this->channelServiceActions = $this->createMock(ChannelServiceActionsPort::class);
        $this->channelServiceActions->expects(self::never())->method('setChannelMemberMode');
        $this->rebuildSubscriber();

        $event = new ModeReceivedEvent(
            channelName: new ChannelName('#test'),
            modeStr: '+e',
            modeParams: ['*!*@exempt.host'],
        );
        $this->subscriber->onModeReceived($event);
    }

    #[Test]
    public function onModeReceivedWithListModeI(): void
    {
        $channel = $this->createStub(\App\Domain\ChanServ\Entity\RegisteredChannel::class);
        $channel->method('isSecure')->willReturn(true);

        $this->channelRepository = $this->createMock(RegisteredChannelRepositoryInterface::class);
        $this->channelRepository->expects(self::once())->method('findByChannelName')->with('#test')->willReturn($channel);
        $this->channelServiceActions = $this->createMock(ChannelServiceActionsPort::class);
        $this->channelServiceActions->expects(self::never())->method('setChannelMemberMode');
        $this->rebuildSubscriber();

        $event = new ModeReceivedEvent(
            channelName: new ChannelName('#test'),
            modeStr: '+I',
            modeParams: ['*!*@invite.host'],
        );
        $this->subscriber->onModeReceived($event);
    }

    #[Test]
    public function onModeReceivedWhenRankAtOrBelowDesiredDoesNotStrip(): void
    {
        $channel = $this->createStub(\App\Domain\ChanServ\Entity\RegisteredChannel::class);
        $channel->method('isSecure')->willReturn(true);
        $channel->method('getId')->willReturn(self::CHANNEL_ID);

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
        $this->channelRepository->expects(self::once())->method('findByChannelName')->with('#test')->willReturn($channel);
        $this->userLookup = $this->createMock(NetworkUserLookupPort::class);
        $this->userLookup->expects(self::once())->method('findByUid')->with('001USER')->willReturn($sender);
        $this->nickRepository = $this->createMock(RegisteredNickRepositoryInterface::class);
        $this->nickRepository->expects(self::once())->method('findByNick')->with('TestUser')->willReturn($account);
        $this->accessRepository = $this->createMock(ChannelAccessRepositoryInterface::class);
        $this->accessRepository->expects(self::once())->method('findByChannelAndNick')->with(self::CHANNEL_ID, self::NICK_ID)->willReturn($accessStub);
        $this->levelRepository = $this->createStub(ChannelLevelRepositoryInterface::class);
        $this->levelRepository->method('findByChannelAndKey')->willReturnCallback(
            static fn (int $channelId, string $key): ?ChannelLevel => match ($key) {
                ChannelLevel::KEY_AUTOOP => new ChannelLevel($channelId, $key, 100),
                default => null,
            },
        );
        $this->modeSupportProvider = $this->createStub(ActiveChannelModeSupportProviderInterface::class);
        $this->modeSupportProvider->method('getSupport')->willReturn($modeSupport);
        $this->channelServiceActions = $this->createMock(ChannelServiceActionsPort::class);
        $this->channelServiceActions->expects(self::never())->method('setChannelMemberMode');
        $this->rebuildSubscriber();

        $event = new ModeReceivedEvent(
            channelName: new ChannelName('#test'),
            modeStr: '+v',
            modeParams: ['001USER'],
        );
        $this->subscriber->onModeReceived($event);
    }

    #[Test]
    public function syncRanksForChannelSkipsChanservUid(): void
    {
        $channel = $this->createStub(\App\Domain\ChanServ\Entity\RegisteredChannel::class);
        $channel->method('getName')->willReturn('#test');
        $channel->method('isSecure')->willReturn(false);

        $view = new ChannelView(
            name: '#test',
            modes: '+nt',
            topic: null,
            memberCount: 1,
            members: [
                ['uid' => self::CHANSERV_UID, 'roleLetter' => 'o', 'prefixLetters' => ['o']],
            ],
        );
        $modeSupport = $this->createStub(ChannelModeSupportInterface::class);
        $modeSupport->method('getSupportedPrefixModes')->willReturn(['q', 'a', 'o', 'h', 'v']);

        $this->channelRepository = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $this->channelRepository->method('listAll')->willReturn([$channel]);
        $this->channelLookup = $this->createMock(ChannelLookupPort::class);
        $this->channelLookup->expects(self::once())->method('findByChannelName')->with('#test')->willReturn($view);
        $this->modeSupportProvider = $this->createStub(ActiveChannelModeSupportProviderInterface::class);
        $this->modeSupportProvider->method('getSupport')->willReturn($modeSupport);
        $this->userLookup = $this->createMock(NetworkUserLookupPort::class);
        $this->userLookup->expects(self::never())->method('findByUid');
        $this->channelServiceActions = $this->createMock(ChannelServiceActionsPort::class);
        $this->channelServiceActions->expects(self::never())->method('setChannelModes');
        $this->rebuildSubscriber();

        $connection = $this->createStub(ConnectionInterface::class);
        $this->subscriber->onSyncComplete(new NetworkSyncCompleteEvent($connection, '001'));
    }

    #[Test]
    public function syncRanksForChannelWhenMemberContextNullSkipsMember(): void
    {
        $channel = $this->createStub(\App\Domain\ChanServ\Entity\RegisteredChannel::class);
        $channel->method('getName')->willReturn('#test');
        $channel->method('isSecure')->willReturn(false);

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

        $this->channelRepository = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $this->channelRepository->method('listAll')->willReturn([$channel]);
        $this->channelLookup = $this->createMock(ChannelLookupPort::class);
        $this->channelLookup->expects(self::once())->method('findByChannelName')->with('#test')->willReturn($view);
        $this->modeSupportProvider = $this->createStub(ActiveChannelModeSupportProviderInterface::class);
        $this->modeSupportProvider->method('getSupport')->willReturn($modeSupport);
        $this->userLookup = $this->createMock(NetworkUserLookupPort::class);
        $this->userLookup->expects(self::once())->method('findByUid')->with('001USER')->willReturn(null);
        $this->userLookup->expects(self::once())->method('findByNick')->with('001USER')->willReturn(null);
        $this->channelServiceActions = $this->createMock(ChannelServiceActionsPort::class);
        $this->channelServiceActions->expects(self::never())->method('setChannelModes');
        $this->rebuildSubscriber();

        $connection = $this->createStub(ConnectionInterface::class);
        $this->subscriber->onSyncComplete(new NetworkSyncCompleteEvent($connection, '001'));
    }

    #[Test]
    public function syncRanksForChannelWhenCurrentRankEqualsDesiredDoesNothing(): void
    {
        $channel = $this->createMock(\App\Domain\ChanServ\Entity\RegisteredChannel::class);
        $channel->method('getName')->willReturn('#test');
        $channel->method('getId')->willReturn(self::CHANNEL_ID);
        $channel->method('isSecure')->willReturn(false);
        $channel->expects(self::atLeastOnce())->method('isFounder')->with(self::NICK_ID)->willReturn(false);

        $view = new ChannelView(
            name: '#test',
            modes: '+nt',
            topic: null,
            memberCount: 1,
            members: [
                ['uid' => '001USER', 'roleLetter' => 'o', 'prefixLetters' => ['o']],
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

        $this->channelRepository = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $this->channelRepository->method('listAll')->willReturn([$channel]);
        $this->channelLookup = $this->createMock(ChannelLookupPort::class);
        $this->channelLookup->expects(self::once())->method('findByChannelName')->with('#test')->willReturn($view);
        $this->modeSupportProvider = $this->createStub(ActiveChannelModeSupportProviderInterface::class);
        $this->modeSupportProvider->method('getSupport')->willReturn($modeSupport);
        $this->userLookup = $this->createMock(NetworkUserLookupPort::class);
        $this->userLookup->expects(self::once())->method('findByUid')->with('001USER')->willReturn($sender);
        $this->nickRepository = $this->createMock(RegisteredNickRepositoryInterface::class);
        $this->nickRepository->expects(self::once())->method('findByNick')->with('TestUser')->willReturn($account);
        $this->accessRepository = $this->createMock(ChannelAccessRepositoryInterface::class);
        $this->accessRepository->expects(self::once())->method('findByChannelAndNick')->with(self::CHANNEL_ID, self::NICK_ID)->willReturn($accessStub);
        $this->levelRepository = $this->createStub(ChannelLevelRepositoryInterface::class);
        $this->levelRepository->method('findByChannelAndKey')->willReturnCallback(
            static fn (int $channelId, string $key): ?ChannelLevel => match ($key) {
                ChannelLevel::KEY_AUTOOP => new ChannelLevel($channelId, $key, 100),
                default => null,
            },
        );
        $this->channelServiceActions = $this->createMock(ChannelServiceActionsPort::class);
        $this->channelServiceActions->expects(self::never())->method('setChannelModes');
        $this->rebuildSubscriber();

        $connection = $this->createStub(ConnectionInterface::class);
        $this->subscriber->onSyncComplete(new NetworkSyncCompleteEvent($connection, '001'));
    }

    #[Test]
    public function syncRanksForChannelSecureNoAccessStripsAll(): void
    {
        $channel = $this->createStub(\App\Domain\ChanServ\Entity\RegisteredChannel::class);
        $channel->method('getName')->willReturn('#test');
        $channel->method('isSecure')->willReturn(true);

        $view = new ChannelView(
            name: '#test',
            modes: '+nt',
            topic: null,
            memberCount: 1,
            members: [
                ['uid' => '001USER', 'roleLetter' => 'o', 'prefixLetters' => ['o']],
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
            isIdentified: false,
        );

        $this->channelRepository = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $this->channelRepository->method('listAll')->willReturn([$channel]);
        $this->channelLookup = $this->createMock(ChannelLookupPort::class);
        $this->channelLookup->expects(self::once())->method('findByChannelName')->with('#test')->willReturn($view);
        $this->modeSupportProvider = $this->createStub(ActiveChannelModeSupportProviderInterface::class);
        $this->modeSupportProvider->method('getSupport')->willReturn($modeSupport);
        $this->userLookup = $this->createMock(NetworkUserLookupPort::class);
        $this->userLookup->expects(self::once())->method('findByUid')->with('001USER')->willReturn($sender);
        $this->nickRepository = $this->createMock(RegisteredNickRepositoryInterface::class);
        $this->nickRepository->expects(self::once())->method('findByNick')->with('TestUser')->willReturn(null);
        $this->channelServiceActions = $this->createMock(ChannelServiceActionsPort::class);
        $this->channelServiceActions->expects(self::once())->method('setChannelModes')->with('#test', '-o', ['001USER']);
        $this->rebuildSubscriber();

        $connection = $this->createStub(ConnectionInterface::class);
        $this->subscriber->onSyncComplete(new NetworkSyncCompleteEvent($connection, '001'));
    }

    #[Test]
    public function onMessageReceivedCallsSnapshotPendingAtStart(): void
    {
        $registry = new ChannelRankSyncPendingRegistry();
        $registry->add('#test');

        $this->syncPendingRegistry = $registry;
        $this->rebuildSubscriber();

        self::assertSame([], $registry->getPendingAtStart());

        $this->subscriber->onMessageReceived();

        self::assertSame(['#test'], $registry->getPendingAtStart());
    }

    #[Test]
    public function onIrcMessageProcessedWithEmptyPendingDoesNothing(): void
    {
        $registry = new ChannelRankSyncPendingRegistry();
        $registry->snapshotPendingAtStart();

        $this->syncPendingRegistry = $registry;
        $this->channelRepository = $this->createMock(RegisteredChannelRepositoryInterface::class);
        $this->channelRepository->expects(self::never())->method('findByChannelName');
        $this->rebuildSubscriber();

        $this->subscriber->onIrcMessageProcessed();
    }

    #[Test]
    public function onIrcMessageProcessedWithPendingChannelProcessesSync(): void
    {
        $channel = $this->createMock(\App\Domain\ChanServ\Entity\RegisteredChannel::class);
        $channel->expects(self::atLeastOnce())->method('getName')->willReturn('#test');
        $channel->method('isSecure')->willReturn(false);

        $view = new ChannelView(
            name: '#test',
            modes: '+nt',
            topic: null,
            memberCount: 1,
            members: [],
        );
        $modeSupport = $this->createStub(ChannelModeSupportInterface::class);
        $modeSupport->method('getSupportedPrefixModes')->willReturn(['q', 'a', 'o', 'h', 'v']);

        $registry = new ChannelRankSyncPendingRegistry();
        $registry->add('#test');
        $registry->snapshotPendingAtStart();

        $this->syncPendingRegistry = $registry;
        $this->channelRepository = $this->createMock(RegisteredChannelRepositoryInterface::class);
        $this->channelRepository->expects(self::once())->method('findByChannelName')->with('#test')->willReturn($channel);
        $this->channelLookup = $this->createMock(ChannelLookupPort::class);
        $this->channelLookup->expects(self::once())->method('findByChannelName')->with('#test')->willReturn($view);
        $this->modeSupportProvider = $this->createStub(ActiveChannelModeSupportProviderInterface::class);
        $this->modeSupportProvider->method('getSupport')->willReturn($modeSupport);
        $this->channelServiceActions = $this->createMock(ChannelServiceActionsPort::class);
        $this->channelServiceActions->expects(self::never())->method('setChannelModes');
        $this->rebuildSubscriber();

        $this->subscriber->onIrcMessageProcessed();

        self::assertSame([], $registry->getPendingAtStart());
    }

    #[Test]
    public function onIrcMessageProcessedWithNonExistentChannelSkipsSync(): void
    {
        $registry = new ChannelRankSyncPendingRegistry();
        $registry->add('#nonexistent');
        $registry->snapshotPendingAtStart();

        $this->syncPendingRegistry = $registry;
        $this->channelRepository = $this->createMock(RegisteredChannelRepositoryInterface::class);
        $this->channelRepository->expects(self::once())->method('findByChannelName')->with('#nonexistent')->willReturn(null);
        $this->channelLookup = $this->createMock(ChannelLookupPort::class);
        $this->channelLookup->expects(self::never())->method('findByChannelName');
        $this->channelServiceActions = $this->createMock(ChannelServiceActionsPort::class);
        $this->channelServiceActions->expects(self::never())->method('setChannelModes');
        $this->rebuildSubscriber();

        $this->subscriber->onIrcMessageProcessed();

        self::assertSame([], $registry->getPendingAtStart());
    }

    #[Test]
    public function onIrcMessageProcessedWithMultiplePendingChannels(): void
    {
        $channel1 = $this->createStub(\App\Domain\ChanServ\Entity\RegisteredChannel::class);
        $channel1->method('getName')->willReturn('#chan1');
        $channel1->method('isSecure')->willReturn(false);

        $view1 = new ChannelView(name: '#chan1', modes: '+nt', topic: null, memberCount: 1, members: []);
        $modeSupport = $this->createStub(ChannelModeSupportInterface::class);
        $modeSupport->method('getSupportedPrefixModes')->willReturn(['q', 'a', 'o', 'h', 'v']);

        $registry = new ChannelRankSyncPendingRegistry();
        $registry->add('#chan1');
        $registry->add('#nonexistent');
        $registry->add('#chan3');
        $registry->snapshotPendingAtStart();

        $this->syncPendingRegistry = $registry;
        $this->channelRepository = $this->createMock(RegisteredChannelRepositoryInterface::class);
        $this->channelRepository->expects(self::exactly(3))->method('findByChannelName')->willReturnCallback(
            static fn (string $name) => match ($name) {
                '#chan1' => $channel1,
                '#nonexistent' => null,
                '#chan3' => null,
                default => null,
            },
        );
        $this->channelLookup = $this->createMock(ChannelLookupPort::class);
        $this->channelLookup->expects(self::once())->method('findByChannelName')->with('#chan1')->willReturn($view1);
        $this->modeSupportProvider = $this->createStub(ActiveChannelModeSupportProviderInterface::class);
        $this->modeSupportProvider->method('getSupport')->willReturn($modeSupport);
        $this->rebuildSubscriber();

        $this->subscriber->onIrcMessageProcessed();

        self::assertSame([], $registry->getPendingAtStart());
    }

    #[Test]
    public function onModeReceivedWhenTargetUserNotInNetworkSkipsIteration(): void
    {
        $channel = $this->createStub(\App\Domain\ChanServ\Entity\RegisteredChannel::class);
        $channel->method('isSecure')->willReturn(true);
        $channel->method('getId')->willReturn(self::CHANNEL_ID);

        $this->channelRepository = $this->createMock(RegisteredChannelRepositoryInterface::class);
        $this->channelRepository->expects(self::once())->method('findByChannelName')->with('#test')->willReturn($channel);
        $this->userLookup = $this->createMock(NetworkUserLookupPort::class);
        $this->userLookup->expects(self::once())->method('findByUid')->with('001UNKNOWN')->willReturn(null);
        $this->userLookup->expects(self::once())->method('findByNick')->with('001UNKNOWN')->willReturn(null);
        $this->channelServiceActions = $this->createMock(ChannelServiceActionsPort::class);
        $this->channelServiceActions->expects(self::never())->method('setChannelMemberMode');
        $this->rebuildSubscriber();

        $event = new ModeReceivedEvent(
            channelName: new ChannelName('#test'),
            modeStr: '+o',
            modeParams: ['001UNKNOWN'],
        );
        $this->subscriber->onModeReceived($event);
    }

    #[Test]
    public function onChannelSecureEnabledWhenChannelNotFoundDoesNothing(): void
    {
        $registry = new ChannelRankSyncPendingRegistry();

        $this->syncPendingRegistry = $registry;
        $this->channelRepository = $this->createMock(RegisteredChannelRepositoryInterface::class);
        $this->channelRepository->expects(self::once())->method('findByChannelName')->with('#test')->willReturn(null);
        $this->rebuildSubscriber();

        $this->subscriber->onChannelSecureEnabled(new ChannelSecureEnabledEvent('#test'));

        self::assertSame([], $registry->getPendingAtStart());
    }

    #[Test]
    public function onChannelSecureEnabledWhenChannelNotSecureDoesNothing(): void
    {
        $channel = $this->createStub(\App\Domain\ChanServ\Entity\RegisteredChannel::class);
        $channel->method('isSecure')->willReturn(false);

        $registry = new ChannelRankSyncPendingRegistry();

        $this->syncPendingRegistry = $registry;
        $this->channelRepository = $this->createMock(RegisteredChannelRepositoryInterface::class);
        $this->channelRepository->expects(self::once())->method('findByChannelName')->with('#test')->willReturn($channel);
        $this->rebuildSubscriber();

        $this->subscriber->onChannelSecureEnabled(new ChannelSecureEnabledEvent('#test'));

        self::assertSame([], $registry->getPendingAtStart());
    }

    #[Test]
    public function onChannelSecureEnabledWhenChannelIsSecureAddsToPendingRegistry(): void
    {
        $channel = $this->createMock(\App\Domain\ChanServ\Entity\RegisteredChannel::class);
        $channel->method('isSecure')->willReturn(true);
        $channel->expects(self::once())->method('getName')->willReturn('#test');

        $registry = new ChannelRankSyncPendingRegistry();

        $this->syncPendingRegistry = $registry;
        $this->channelRepository = $this->createMock(RegisteredChannelRepositoryInterface::class);
        $this->channelRepository->expects(self::once())->method('findByChannelName')->with('#test')->willReturn($channel);
        $this->rebuildSubscriber();

        $this->subscriber->onChannelSecureEnabled(new ChannelSecureEnabledEvent('#test'));

        $registry->snapshotPendingAtStart();
        self::assertSame(['#test'], $registry->getPendingAtStart());
    }

    #[Test]
    public function onChannelFounderChangedWhenChannelNotFoundDoesNothing(): void
    {
        $registry = new ChannelRankSyncPendingRegistry();

        $this->syncPendingRegistry = $registry;
        $this->channelRepository = $this->createMock(RegisteredChannelRepositoryInterface::class);
        $this->channelRepository->expects(self::once())->method('findByChannelName')->with('#nonexistent')->willReturn(null);
        $this->rebuildSubscriber();

        $this->subscriber->onChannelFounderChanged(new ChannelFounderChangedEvent('#nonexistent'));

        self::assertSame([], $registry->getPendingAtStart());
    }

    #[Test]
    public function onChannelFounderChangedWhenChannelFoundAddsToPendingRegistry(): void
    {
        $channel = $this->createMock(\App\Domain\ChanServ\Entity\RegisteredChannel::class);
        $channel->expects(self::once())->method('getName')->willReturn('#test');

        $registry = new ChannelRankSyncPendingRegistry();

        $this->syncPendingRegistry = $registry;
        $this->channelRepository = $this->createMock(RegisteredChannelRepositoryInterface::class);
        $this->channelRepository->expects(self::once())->method('findByChannelName')->with('#test')->willReturn($channel);
        $this->rebuildSubscriber();

        $this->subscriber->onChannelFounderChanged(new ChannelFounderChangedEvent('#test'));

        $registry->snapshotPendingAtStart();
        self::assertSame(['#test'], $registry->getPendingAtStart());
    }

    #[Test]
    public function collectOpsForSecureStripReturnsCorrectUsers(): void
    {
        $channel = $this->createMock(\App\Domain\ChanServ\Entity\RegisteredChannel::class);
        $channel->expects(self::atLeastOnce())->method('getName')->willReturn('#test');
        $channel->method('isSecure')->willReturn(true);

        $view = new ChannelView(
            name: '#test',
            modes: '+nt',
            topic: null,
            memberCount: 1,
            members: [
                ['uid' => '001USER', 'roleLetter' => 'q', 'prefixLetters' => ['q', 'a', 'o']],
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
            isIdentified: false,
        );

        $this->channelRepository = $this->createMock(RegisteredChannelRepositoryInterface::class);
        $this->channelRepository->expects(self::atLeastOnce())->method('listAll')->willReturn([$channel]);
        $this->channelLookup = $this->createMock(ChannelLookupPort::class);
        $this->channelLookup->expects(self::atLeastOnce())->method('findByChannelName')->with('#test')->willReturn($view);
        $this->modeSupportProvider = $this->createMock(ActiveChannelModeSupportProviderInterface::class);
        $this->modeSupportProvider->expects(self::atLeastOnce())->method('getSupport')->willReturn($modeSupport);
        $this->userLookup = $this->createMock(NetworkUserLookupPort::class);
        $this->userLookup->expects(self::atLeastOnce())->method('findByUid')->with('001USER')->willReturn($sender);
        $this->nickRepository = $this->createMock(RegisteredNickRepositoryInterface::class);
        $this->nickRepository->expects(self::atLeastOnce())->method('findByNick')->with('TestUser')->willReturn(null);
        $this->channelServiceActions = $this->createMock(ChannelServiceActionsPort::class);
        $this->channelServiceActions->expects(self::once())->method('setChannelModes')->with(
            '#test',
            '-qao',
            ['001USER', '001USER', '001USER'],
        );
        $this->rebuildSubscriber();

        $connection = $this->createStub(ConnectionInterface::class);
        $this->subscriber->onSyncComplete(new NetworkSyncCompleteEvent($connection, '001'));
    }

    #[Test]
    public function collectOpsForSecureStripWithPrefixLettersFallback(): void
    {
        $channel = $this->createMock(\App\Domain\ChanServ\Entity\RegisteredChannel::class);
        $channel->expects(self::atLeastOnce())->method('getName')->willReturn('#test');
        $channel->method('isSecure')->willReturn(true);

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
            isIdentified: false,
        );

        $this->channelRepository = $this->createMock(RegisteredChannelRepositoryInterface::class);
        $this->channelRepository->expects(self::atLeastOnce())->method('listAll')->willReturn([$channel]);
        $this->channelLookup = $this->createMock(ChannelLookupPort::class);
        $this->channelLookup->expects(self::atLeastOnce())->method('findByChannelName')->with('#test')->willReturn($view);
        $this->modeSupportProvider = $this->createMock(ActiveChannelModeSupportProviderInterface::class);
        $this->modeSupportProvider->expects(self::atLeastOnce())->method('getSupport')->willReturn($modeSupport);
        $this->userLookup = $this->createMock(NetworkUserLookupPort::class);
        $this->userLookup->expects(self::atLeastOnce())->method('findByUid')->with('001USER')->willReturn($sender);
        $this->nickRepository = $this->createMock(RegisteredNickRepositoryInterface::class);
        $this->nickRepository->expects(self::atLeastOnce())->method('findByNick')->with('TestUser')->willReturn(null);
        $this->channelServiceActions = $this->createMock(ChannelServiceActionsPort::class);
        $this->channelServiceActions->expects(self::never())->method('setChannelModes');
        $this->rebuildSubscriber();

        $connection = $this->createStub(ConnectionInterface::class);
        $this->subscriber->onSyncComplete(new NetworkSyncCompleteEvent($connection, '001'));
    }

    #[Test]
    public function syncRanksForChannelStripsRanksOnSecureChannel(): void
    {
        $channel = $this->createMock(\App\Domain\ChanServ\Entity\RegisteredChannel::class);
        $channel->expects(self::atLeastOnce())->method('getName')->willReturn('#test');
        $channel->method('isSecure')->willReturn(true);

        $view = new ChannelView(
            name: '#test',
            modes: '+nt',
            topic: null,
            memberCount: 1,
            members: [
                ['uid' => '001USER', 'roleLetter' => 'o', 'prefixLetters' => ['o']],
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
            isIdentified: false,
        );

        $this->channelRepository = $this->createMock(RegisteredChannelRepositoryInterface::class);
        $this->channelRepository->expects(self::atLeastOnce())->method('listAll')->willReturn([$channel]);
        $this->channelLookup = $this->createMock(ChannelLookupPort::class);
        $this->channelLookup->expects(self::atLeastOnce())->method('findByChannelName')->with('#test')->willReturn($view);
        $this->modeSupportProvider = $this->createMock(ActiveChannelModeSupportProviderInterface::class);
        $this->modeSupportProvider->expects(self::atLeastOnce())->method('getSupport')->willReturn($modeSupport);
        $this->userLookup = $this->createMock(NetworkUserLookupPort::class);
        $this->userLookup->expects(self::atLeastOnce())->method('findByUid')->with('001USER')->willReturn($sender);
        $this->nickRepository = $this->createMock(RegisteredNickRepositoryInterface::class);
        $this->nickRepository->expects(self::atLeastOnce())->method('findByNick')->with('TestUser')->willReturn(null);
        $this->channelServiceActions = $this->createMock(ChannelServiceActionsPort::class);
        $this->channelServiceActions->expects(self::once())->method('setChannelModes')->with(
            '#test',
            '-o',
            ['001USER'],
        );
        $this->rebuildSubscriber();

        $connection = $this->createStub(ConnectionInterface::class);
        $this->subscriber->onSyncComplete(new NetworkSyncCompleteEvent($connection, '001'));
    }

    #[Test]
    public function syncRanksForChannelSecureWithEmptyCurrentLetterNoStrip(): void
    {
        $channel = $this->createMock(\App\Domain\ChanServ\Entity\RegisteredChannel::class);
        $channel->expects(self::atLeastOnce())->method('getName')->willReturn('#test');
        $channel->method('isSecure')->willReturn(true);

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
            isIdentified: false,
        );

        $this->channelRepository = $this->createMock(RegisteredChannelRepositoryInterface::class);
        $this->channelRepository->expects(self::atLeastOnce())->method('listAll')->willReturn([$channel]);
        $this->channelLookup = $this->createMock(ChannelLookupPort::class);
        $this->channelLookup->expects(self::atLeastOnce())->method('findByChannelName')->with('#test')->willReturn($view);
        $this->modeSupportProvider = $this->createMock(ActiveChannelModeSupportProviderInterface::class);
        $this->modeSupportProvider->expects(self::atLeastOnce())->method('getSupport')->willReturn($modeSupport);
        $this->userLookup = $this->createMock(NetworkUserLookupPort::class);
        $this->userLookup->expects(self::atLeastOnce())->method('findByUid')->with('001USER')->willReturn($sender);
        $this->nickRepository = $this->createMock(RegisteredNickRepositoryInterface::class);
        $this->nickRepository->expects(self::atLeastOnce())->method('findByNick')->with('TestUser')->willReturn(null);
        $this->channelServiceActions = $this->createMock(ChannelServiceActionsPort::class);
        $this->channelServiceActions->expects(self::never())->method('setChannelModes');
        $this->rebuildSubscriber();

        $connection = $this->createStub(ConnectionInterface::class);
        $this->subscriber->onSyncComplete(new NetworkSyncCompleteEvent($connection, '001'));
    }

    #[Test]
    public function onUserJoinedChannelSecureNoAccessWithRoleStripsRank(): void
    {
        $channel = $this->createMock(\App\Domain\ChanServ\Entity\RegisteredChannel::class);
        $channel->method('getId')->willReturn(self::CHANNEL_ID);
        $channel->method('isSecure')->willReturn(true);
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
        $modeSupport = $this->createStub(ChannelModeSupportInterface::class);
        $modeSupport->method('getSupportedPrefixModes')->willReturn(['q', 'a', 'o', 'h', 'v']);

        $this->channelRepository = $this->createMock(RegisteredChannelRepositoryInterface::class);
        $this->channelRepository->expects(self::once())->method('findByChannelName')->with('#test')->willReturn($channel);
        $this->userLookup = $this->createMock(NetworkUserLookupPort::class);
        $this->userLookup->expects(self::once())->method('findByUid')->with('001USER')->willReturn($sender);
        $this->nickRepository = $this->createMock(RegisteredNickRepositoryInterface::class);
        $this->nickRepository->expects(self::once())->method('findByNick')->with('TestUser')->willReturn($account);
        $this->accessRepository = $this->createMock(ChannelAccessRepositoryInterface::class);
        $this->accessRepository->expects(self::once())->method('findByChannelAndNick')->with(self::CHANNEL_ID, self::NICK_ID)->willReturn(null);
        $this->modeSupportProvider = $this->createStub(ActiveChannelModeSupportProviderInterface::class);
        $this->modeSupportProvider->method('getSupport')->willReturn($modeSupport);
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
    public function onUserJoinedChannelSecureNoAccessWithVoiceStripsVoice(): void
    {
        $channel = $this->createMock(\App\Domain\ChanServ\Entity\RegisteredChannel::class);
        $channel->method('getId')->willReturn(self::CHANNEL_ID);
        $channel->method('isSecure')->willReturn(true);
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
        $modeSupport = $this->createStub(ChannelModeSupportInterface::class);
        $modeSupport->method('getSupportedPrefixModes')->willReturn(['q', 'a', 'o', 'h', 'v']);

        $this->channelRepository = $this->createMock(RegisteredChannelRepositoryInterface::class);
        $this->channelRepository->expects(self::once())->method('findByChannelName')->with('#test')->willReturn($channel);
        $this->userLookup = $this->createMock(NetworkUserLookupPort::class);
        $this->userLookup->expects(self::once())->method('findByUid')->with('001USER')->willReturn($sender);
        $this->nickRepository = $this->createMock(RegisteredNickRepositoryInterface::class);
        $this->nickRepository->expects(self::once())->method('findByNick')->with('TestUser')->willReturn($account);
        $this->accessRepository = $this->createMock(ChannelAccessRepositoryInterface::class);
        $this->accessRepository->expects(self::once())->method('findByChannelAndNick')->with(self::CHANNEL_ID, self::NICK_ID)->willReturn(null);
        $this->modeSupportProvider = $this->createStub(ActiveChannelModeSupportProviderInterface::class);
        $this->modeSupportProvider->method('getSupport')->willReturn($modeSupport);
        $this->channelServiceActions = $this->createMock(ChannelServiceActionsPort::class);
        $this->channelServiceActions->expects(self::exactly(2))->method('setChannelMemberMode');
        $this->channelServiceActions->expects(self::exactly(2))->method('setChannelMemberMode')->willReturnCallback(static function (string $channel, string $uid, string $mode, bool $add): void {
            static $calls = 0;
            ++$calls;
            self::assertSame('v', $mode);
            self::assertFalse($add);
        });
        $this->rebuildSubscriber();

        $event = new UserJoinedChannelEvent(
            uid: new Uid('001USER'),
            channel: new ChannelName('#test'),
            role: ChannelMemberRole::Voice,
        );
        $this->subscriber->onUserJoinedChannel($event);
    }

    #[Test]
    public function syncRanksForChannelSecureStripUsingPrefixLettersFromSjoin(): void
    {
        $channel = $this->createMock(\App\Domain\ChanServ\Entity\RegisteredChannel::class);
        $channel->expects(self::atLeastOnce())->method('getName')->willReturn('#test');
        $channel->method('isSecure')->willReturn(true);

        $view = new ChannelView(
            name: '#test',
            modes: '+nt',
            topic: null,
            memberCount: 1,
            members: [
                ['uid' => '001USER', 'roleLetter' => 'q', 'prefixLetters' => ['q', 'a', 'o', 'h', 'v']],
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
            isIdentified: false,
        );

        $this->channelRepository = $this->createMock(RegisteredChannelRepositoryInterface::class);
        $this->channelRepository->expects(self::atLeastOnce())->method('listAll')->willReturn([$channel]);
        $this->channelLookup = $this->createMock(ChannelLookupPort::class);
        $this->channelLookup->expects(self::atLeastOnce())->method('findByChannelName')->with('#test')->willReturn($view);
        $this->modeSupportProvider = $this->createMock(ActiveChannelModeSupportProviderInterface::class);
        $this->modeSupportProvider->expects(self::atLeastOnce())->method('getSupport')->willReturn($modeSupport);
        $this->userLookup = $this->createMock(NetworkUserLookupPort::class);
        $this->userLookup->expects(self::atLeastOnce())->method('findByUid')->with('001USER')->willReturn($sender);
        $this->nickRepository = $this->createMock(RegisteredNickRepositoryInterface::class);
        $this->nickRepository->expects(self::atLeastOnce())->method('findByNick')->with('TestUser')->willReturn(null);
        $this->channelServiceActions = $this->createMock(ChannelServiceActionsPort::class);
        $this->channelServiceActions->expects(self::once())->method('setChannelModes')->with(
            '#test',
            '-qaohv',
            ['001USER', '001USER', '001USER', '001USER', '001USER'],
        );
        $this->rebuildSubscriber();

        $connection = $this->createStub(ConnectionInterface::class);
        $this->subscriber->onSyncComplete(new NetworkSyncCompleteEvent($connection, '001'));
    }

    #[Test]
    public function syncRanksForChannelSecureStripWithPartialPrefixLetters(): void
    {
        $channel = $this->createMock(\App\Domain\ChanServ\Entity\RegisteredChannel::class);
        $channel->expects(self::atLeastOnce())->method('getName')->willReturn('#test');
        $channel->method('isSecure')->willReturn(true);

        $view = new ChannelView(
            name: '#test',
            modes: '+nt',
            topic: null,
            memberCount: 1,
            members: [
                ['uid' => '001USER', 'roleLetter' => 'o', 'prefixLetters' => ['o', 'v']],
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
            isIdentified: false,
        );

        $this->channelRepository = $this->createMock(RegisteredChannelRepositoryInterface::class);
        $this->channelRepository->expects(self::atLeastOnce())->method('listAll')->willReturn([$channel]);
        $this->channelLookup = $this->createMock(ChannelLookupPort::class);
        $this->channelLookup->expects(self::atLeastOnce())->method('findByChannelName')->with('#test')->willReturn($view);
        $this->modeSupportProvider = $this->createMock(ActiveChannelModeSupportProviderInterface::class);
        $this->modeSupportProvider->expects(self::atLeastOnce())->method('getSupport')->willReturn($modeSupport);
        $this->userLookup = $this->createMock(NetworkUserLookupPort::class);
        $this->userLookup->expects(self::atLeastOnce())->method('findByUid')->with('001USER')->willReturn($sender);
        $this->nickRepository = $this->createMock(RegisteredNickRepositoryInterface::class);
        $this->nickRepository->expects(self::atLeastOnce())->method('findByNick')->with('TestUser')->willReturn(null);
        $this->channelServiceActions = $this->createMock(ChannelServiceActionsPort::class);
        $this->channelServiceActions->expects(self::once())->method('setChannelModes')->with(
            '#test',
            '-ov',
            ['001USER', '001USER'],
        );
        $this->rebuildSubscriber();

        $connection = $this->createStub(ConnectionInterface::class);
        $this->subscriber->onSyncComplete(new NetworkSyncCompleteEvent($connection, '001'));
    }

    #[Test]
    public function syncRanksForChannelSecureStripWithoutQModeSupport(): void
    {
        $channel = $this->createMock(\App\Domain\ChanServ\Entity\RegisteredChannel::class);
        $channel->expects(self::atLeastOnce())->method('getName')->willReturn('#test');
        $channel->method('isSecure')->willReturn(true);

        $view = new ChannelView(
            name: '#test',
            modes: '+nt',
            topic: null,
            memberCount: 1,
            members: [
                ['uid' => '001USER', 'roleLetter' => 'a', 'prefixLetters' => ['a', 'o', 'h', 'v']],
            ],
        );
        $modeSupport = $this->createStub(ChannelModeSupportInterface::class);
        $modeSupport->method('getSupportedPrefixModes')->willReturn(['a', 'o', 'h', 'v']);
        $sender = new SenderView(
            uid: '001USER',
            nick: 'TestUser',
            ident: '~u',
            hostname: 'user.example.com',
            cloakedHost: 'user.example.com',
            ipBase64: '',
            isIdentified: false,
        );

        $this->channelRepository = $this->createMock(RegisteredChannelRepositoryInterface::class);
        $this->channelRepository->expects(self::atLeastOnce())->method('listAll')->willReturn([$channel]);
        $this->channelLookup = $this->createMock(ChannelLookupPort::class);
        $this->channelLookup->expects(self::atLeastOnce())->method('findByChannelName')->with('#test')->willReturn($view);
        $this->modeSupportProvider = $this->createMock(ActiveChannelModeSupportProviderInterface::class);
        $this->modeSupportProvider->expects(self::atLeastOnce())->method('getSupport')->willReturn($modeSupport);
        $this->userLookup = $this->createMock(NetworkUserLookupPort::class);
        $this->userLookup->expects(self::atLeastOnce())->method('findByUid')->with('001USER')->willReturn($sender);
        $this->nickRepository = $this->createMock(RegisteredNickRepositoryInterface::class);
        $this->nickRepository->expects(self::atLeastOnce())->method('findByNick')->with('TestUser')->willReturn(null);
        $this->channelServiceActions = $this->createMock(ChannelServiceActionsPort::class);
        $this->channelServiceActions->expects(self::once())->method('setChannelModes')->with(
            '#test',
            '-aohv',
            ['001USER', '001USER', '001USER', '001USER'],
        );
        $this->rebuildSubscriber();

        $connection = $this->createStub(ConnectionInterface::class);
        $this->subscriber->onSyncComplete(new NetworkSyncCompleteEvent($connection, '001'));
    }

    #[Test]
    public function syncRanksForChannelRankAboveDesiredWithoutQModeSupport(): void
    {
        $channel = $this->createMock(\App\Domain\ChanServ\Entity\RegisteredChannel::class);
        $channel->expects(self::atLeastOnce())->method('getName')->willReturn('#test');
        $channel->expects(self::atLeastOnce())->method('getId')->willReturn(self::CHANNEL_ID);
        $channel->method('isSecure')->willReturn(false);
        $channel->expects(self::atLeastOnce())->method('isFounder')->with(self::NICK_ID)->willReturn(false);

        $view = new ChannelView(
            name: '#test',
            modes: '+nt',
            topic: null,
            memberCount: 1,
            members: [
                ['uid' => '001USER', 'roleLetter' => 'a', 'prefixLetters' => ['a', 'o', 'v']],
            ],
        );
        $modeSupport = $this->createStub(ChannelModeSupportInterface::class);
        $modeSupport->method('getSupportedPrefixModes')->willReturn(['a', 'o', 'h', 'v']);
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
        $accessStub->method('getLevel')->willReturn(30);

        $this->channelRepository = $this->createMock(RegisteredChannelRepositoryInterface::class);
        $this->channelRepository->expects(self::atLeastOnce())->method('listAll')->willReturn([$channel]);
        $this->channelLookup = $this->createMock(ChannelLookupPort::class);
        $this->channelLookup->expects(self::atLeastOnce())->method('findByChannelName')->with('#test')->willReturn($view);
        $this->modeSupportProvider = $this->createMock(ActiveChannelModeSupportProviderInterface::class);
        $this->modeSupportProvider->expects(self::atLeastOnce())->method('getSupport')->willReturn($modeSupport);
        $this->userLookup = $this->createMock(NetworkUserLookupPort::class);
        $this->userLookup->expects(self::atLeastOnce())->method('findByUid')->with('001USER')->willReturn($sender);
        $this->nickRepository = $this->createMock(RegisteredNickRepositoryInterface::class);
        $this->nickRepository->expects(self::atLeastOnce())->method('findByNick')->with('TestUser')->willReturn($account);
        $this->accessRepository = $this->createMock(ChannelAccessRepositoryInterface::class);
        $this->accessRepository->expects(self::atLeastOnce())->method('findByChannelAndNick')->with(self::CHANNEL_ID, self::NICK_ID)->willReturn($accessStub);
        $this->levelRepository = $this->createMock(ChannelLevelRepositoryInterface::class);
        $this->levelRepository->expects(self::atLeastOnce())->method('findByChannelAndKey')->willReturnCallback(
            static fn (int $channelId, string $key): ?ChannelLevel => match ($key) {
                ChannelLevel::KEY_AUTOVOICE => new ChannelLevel($channelId, $key, 30),
                default => null,
            },
        );
        $this->channelServiceActions = $this->createMock(ChannelServiceActionsPort::class);
        $this->channelServiceActions->expects(self::once())->method('setChannelModes')->with(
            '#test',
            '-ao+v',
            ['001USER', '001USER', '001USER'],
        );
        $this->rebuildSubscriber();

        $connection = $this->createStub(ConnectionInterface::class);
        $this->subscriber->onSyncComplete(new NetworkSyncCompleteEvent($connection, '001'));
    }

    #[Test]
    public function collectOpsWhenRankAboveDesiredWithEmptyCurrentLetter(): void
    {
        $channel = $this->createMock(\App\Domain\ChanServ\Entity\RegisteredChannel::class);
        $channel->expects(self::atLeastOnce())->method('getName')->willReturn('#test');
        $channel->expects(self::atLeastOnce())->method('getId')->willReturn(self::CHANNEL_ID);
        $channel->method('isSecure')->willReturn(false);
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
        $this->nickRepository = $this->createMock(RegisteredNickRepositoryInterface::class);
        $this->nickRepository->expects(self::atLeastOnce())->method('findByNick')->with('TestUser')->willReturn($account);
        $this->accessRepository = $this->createMock(ChannelAccessRepositoryInterface::class);
        $this->accessRepository->expects(self::atLeastOnce())->method('findByChannelAndNick')->with(self::CHANNEL_ID, self::NICK_ID)->willReturn($accessStub);
        $this->levelRepository = $this->createMock(ChannelLevelRepositoryInterface::class);
        $this->levelRepository->expects(self::atLeastOnce())->method('findByChannelAndKey')->willReturnCallback(
            static fn (int $channelId, string $key): ?ChannelLevel => match ($key) {
                ChannelLevel::KEY_AUTOOP => new ChannelLevel($channelId, $key, 100),
                default => null,
            },
        );
        $this->channelServiceActions = $this->createMock(ChannelServiceActionsPort::class);
        $this->channelServiceActions->expects(self::once())->method('setChannelModes')->with('#test', '+o', ['001USER']);
        $this->rebuildSubscriber();

        $connection = $this->createStub(ConnectionInterface::class);
        $this->subscriber->onSyncComplete(new NetworkSyncCompleteEvent($connection, '001'));
    }
}
