<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\ChanServ\Subscriber;

use App\Application\ChanServ\ChanServAccessHelper;
use App\Application\Port\ChannelLookupPort;
use App\Application\Port\ChannelServiceActionsPort;
use App\Application\Port\ChannelView;
use App\Application\Port\NetworkUserLookupPort;
use App\Application\Port\SenderView;
use App\Domain\ChanServ\Entity\ChannelAccess;
use App\Domain\ChanServ\Entity\ChannelLevel;
use App\Domain\ChanServ\Entity\RegisteredChannel;
use App\Domain\ChanServ\Repository\ChannelAccessRepositoryInterface;
use App\Domain\ChanServ\Repository\ChannelLevelRepositoryInterface;
use App\Domain\ChanServ\Repository\RegisteredChannelRepositoryInterface;
use App\Domain\IRC\Connection\ConnectionInterface;
use App\Domain\IRC\Event\NetworkSyncCompleteEvent;
use App\Domain\IRC\Event\UserJoinedChannelEvent;
use App\Domain\IRC\Network\ChannelMemberRole;
use App\Domain\IRC\ValueObject\ChannelName;
use App\Domain\IRC\ValueObject\Uid;
use App\Domain\NickServ\Entity\RegisteredNick;
use App\Domain\NickServ\Repository\RegisteredNickRepositoryInterface;
use App\Infrastructure\ChanServ\Subscriber\ChanServNojoinEnforceSubscriber;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Contracts\Translation\TranslatorInterface;

#[CoversClass(ChanServNojoinEnforceSubscriber::class)]
final class ChanServNojoinEnforceSubscriberTest extends TestCase
{
    private function createSubscriber(
        RegisteredChannelRepositoryInterface $channelRepo,
        ChannelLevelRepositoryInterface $levelRepo,
        RegisteredNickRepositoryInterface $nickRepo,
        ChannelLookupPort $channelLookup,
        NetworkUserLookupPort $userLookup,
        ChannelServiceActionsPort $channelServiceActions,
        ChanServAccessHelper $accessHelper,
    ): ChanServNojoinEnforceSubscriber {
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id, array $params, ?string $domain, ?string $locale): string => 'nojoin.reason' === $id ? 'NOJOIN level restriction' : $id);

        return new ChanServNojoinEnforceSubscriber(
            $channelRepo,
            $levelRepo,
            $nickRepo,
            $channelLookup,
            $userLookup,
            $channelServiceActions,
            $accessHelper,
            $translator,
            'ChanServ',
            'en',
            new NullLogger(),
        );
    }

    #[Test]
    public function kicksUserWithLevelBelowNojoin(): void
    {
        $channel = $this->createStub(RegisteredChannel::class);
        $channel->method('getId')->willReturn(1);
        $channel->method('isFounder')->willReturn(false);

        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channelRepo->method('findByChannelName')->willReturn($channel);

        $nojoinLevel = new ChannelLevel(1, ChannelLevel::KEY_NOJOIN, 50);
        $levelRepo = $this->createStub(ChannelLevelRepositoryInterface::class);
        $levelRepo->method('findByChannelAndKey')->willReturn($nojoinLevel);

        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepo->method('findByNick')->willReturn(null);

        $accessRepo = $this->createStub(ChannelAccessRepositoryInterface::class);
        $accessHelper = new ChanServAccessHelper($accessRepo, $levelRepo);

        $userView = new SenderView(
            'UID1',
            'User',
            'user',
            'user.isp.com',
            'user.isp.com',
            '192.168.1.1',
            false,
            false,
            '001',
            'user.isp.com',
        );

        $userLookup = $this->createStub(NetworkUserLookupPort::class);
        $userLookup->method('findByUid')->willReturn($userView);

        $kicked = [];
        $channelServiceActions = $this->createMock(ChannelServiceActionsPort::class);
        $channelServiceActions->expects(self::once())->method('kickFromChannel')
            ->willReturnCallback(static function (string $channel, string $uid, string $reason) use (&$kicked): void {
                $kicked[] = ['channel' => $channel, 'uid' => $uid, 'reason' => $reason];
            });

        $subscriber = $this->createSubscriber(
            $channelRepo,
            $levelRepo,
            $nickRepo,
            $this->createStub(ChannelLookupPort::class),
            $userLookup,
            $channelServiceActions,
            $accessHelper,
        );

        $event = new UserJoinedChannelEvent(new Uid('UID1'), new ChannelName('#test'), ChannelMemberRole::None);
        $subscriber->onUserJoined($event);

        self::assertCount(1, $kicked);
        self::assertSame('#test', $kicked[0]['channel']);
        self::assertSame('UID1', $kicked[0]['uid']);
        self::assertSame('NOJOIN level restriction', $kicked[0]['reason']);
    }

    #[Test]
    public function doesNotKickUserWithLevelAboveNojoin(): void
    {
        $registeredNick = $this->createStub(RegisteredNick::class);
        $registeredNick->method('getId')->willReturn(10);

        $channel = $this->createStub(RegisteredChannel::class);
        $channel->method('getId')->willReturn(1);
        $channel->method('isFounder')->willReturn(false);

        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channelRepo->method('findByChannelName')->willReturn($channel);

        $nojoinLevel = new ChannelLevel(1, ChannelLevel::KEY_NOJOIN, 50);
        $levelRepo = $this->createStub(ChannelLevelRepositoryInterface::class);
        $levelRepo->method('findByChannelAndKey')->willReturn($nojoinLevel);

        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepo->method('findByNick')->willReturn($registeredNick);

        $channelAccess = $this->createStub(ChannelAccess::class);
        $channelAccess->method('getLevel')->willReturn(100);

        $accessRepo = $this->createStub(ChannelAccessRepositoryInterface::class);
        $accessRepo->method('findByChannelAndNick')->willReturn($channelAccess);
        $accessHelper = new ChanServAccessHelper($accessRepo, $levelRepo);

        $userView = new SenderView(
            'UID1',
            'User',
            'user',
            'user.isp.com',
            'user.isp.com',
            '192.168.1.1',
            true,
            false,
            '001',
            'user.isp.com',
        );

        $userLookup = $this->createStub(NetworkUserLookupPort::class);
        $userLookup->method('findByUid')->willReturn($userView);

        $channelServiceActions = $this->createMock(ChannelServiceActionsPort::class);
        $channelServiceActions->expects(self::never())->method('kickFromChannel');

        $subscriber = $this->createSubscriber(
            $channelRepo,
            $levelRepo,
            $nickRepo,
            $this->createStub(ChannelLookupPort::class),
            $userLookup,
            $channelServiceActions,
            $accessHelper,
        );

        $event = new UserJoinedChannelEvent(new Uid('UID1'), new ChannelName('#test'), ChannelMemberRole::None);
        $subscriber->onUserJoined($event);
    }

    #[Test]
    public function doesNotKickUserWhenNojoinIsMinusOne(): void
    {
        $channel = $this->createStub(RegisteredChannel::class);
        $channel->method('getId')->willReturn(1);
        $channel->method('isFounder')->willReturn(false);

        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channelRepo->method('findByChannelName')->willReturn($channel);

        $nojoinLevel = new ChannelLevel(1, ChannelLevel::KEY_NOJOIN, -1);
        $levelRepo = $this->createStub(ChannelLevelRepositoryInterface::class);
        $levelRepo->method('findByChannelAndKey')->willReturn($nojoinLevel);

        $accessRepo = $this->createStub(ChannelAccessRepositoryInterface::class);
        $accessHelper = new ChanServAccessHelper($accessRepo, $levelRepo);

        $userView = new SenderView(
            'UID1',
            'User',
            'user',
            'user.isp.com',
            'user.isp.com',
            '192.168.1.1',
            false,
            false,
            '001',
            'user.isp.com',
        );

        $userLookup = $this->createStub(NetworkUserLookupPort::class);
        $userLookup->method('findByUid')->willReturn($userView);

        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);

        $channelServiceActions = $this->createMock(ChannelServiceActionsPort::class);
        $channelServiceActions->expects(self::never())->method('kickFromChannel');

        $subscriber = $this->createSubscriber(
            $channelRepo,
            $levelRepo,
            $nickRepo,
            $this->createStub(ChannelLookupPort::class),
            $userLookup,
            $channelServiceActions,
            $accessHelper,
        );

        $event = new UserJoinedChannelEvent(new Uid('UID1'), new ChannelName('#test'), ChannelMemberRole::None);
        $subscriber->onUserJoined($event);
    }

    #[Test]
    public function doesNotKickOperUser(): void
    {
        $channel = $this->createStub(RegisteredChannel::class);
        $channel->method('getId')->willReturn(1);
        $channel->method('isFounder')->willReturn(false);

        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channelRepo->method('findByChannelName')->willReturn($channel);

        $nojoinLevel = new ChannelLevel(1, ChannelLevel::KEY_NOJOIN, 50);
        $levelRepo = $this->createStub(ChannelLevelRepositoryInterface::class);
        $levelRepo->method('findByChannelAndKey')->willReturn($nojoinLevel);

        $accessRepo = $this->createStub(ChannelAccessRepositoryInterface::class);
        $accessHelper = new ChanServAccessHelper($accessRepo, $levelRepo);

        $userView = new SenderView(
            'UID1',
            'Oper',
            'oper',
            'oper.isp.com',
            'oper.isp.com',
            '192.168.1.1',
            false,
            true,
            '001',
            'oper.isp.com',
        );

        $userLookup = $this->createStub(NetworkUserLookupPort::class);
        $userLookup->method('findByUid')->willReturn($userView);

        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);

        $channelServiceActions = $this->createMock(ChannelServiceActionsPort::class);
        $channelServiceActions->expects(self::never())->method('kickFromChannel');

        $subscriber = $this->createSubscriber(
            $channelRepo,
            $levelRepo,
            $nickRepo,
            $this->createStub(ChannelLookupPort::class),
            $userLookup,
            $channelServiceActions,
            $accessHelper,
        );

        $event = new UserJoinedChannelEvent(new Uid('UID1'), new ChannelName('#test'), ChannelMemberRole::None);
        $subscriber->onUserJoined($event);
    }

    #[Test]
    public function doesNotKickChanServ(): void
    {
        $channel = $this->createStub(RegisteredChannel::class);
        $channel->method('getId')->willReturn(1);
        $channel->method('isFounder')->willReturn(false);

        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channelRepo->method('findByChannelName')->willReturn($channel);

        $nojoinLevel = new ChannelLevel(1, ChannelLevel::KEY_NOJOIN, 50);
        $levelRepo = $this->createStub(ChannelLevelRepositoryInterface::class);
        $levelRepo->method('findByChannelAndKey')->willReturn($nojoinLevel);

        $accessRepo = $this->createStub(ChannelAccessRepositoryInterface::class);
        $accessHelper = new ChanServAccessHelper($accessRepo, $levelRepo);

        $userView = new SenderView(
            'UID1',
            'ChanServ',
            'user',
            'user.isp.com',
            'user.isp.com',
            '192.168.1.1',
            false,
            false,
            '001',
            'user.isp.com',
        );

        $userLookup = $this->createStub(NetworkUserLookupPort::class);
        $userLookup->method('findByUid')->willReturn($userView);

        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);

        $channelServiceActions = $this->createMock(ChannelServiceActionsPort::class);
        $channelServiceActions->expects(self::never())->method('kickFromChannel');

        $subscriber = $this->createSubscriber(
            $channelRepo,
            $levelRepo,
            $nickRepo,
            $this->createStub(ChannelLookupPort::class),
            $userLookup,
            $channelServiceActions,
            $accessHelper,
        );

        $event = new UserJoinedChannelEvent(new Uid('UID1'), new ChannelName('#test'), ChannelMemberRole::None);
        $subscriber->onUserJoined($event);
    }

    #[Test]
    public function doesNothingWhenChannelNotRegistered(): void
    {
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channelRepo->method('findByChannelName')->willReturn(null);

        $levelRepo = $this->createStub(ChannelLevelRepositoryInterface::class);
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $accessRepo = $this->createStub(ChannelAccessRepositoryInterface::class);
        $accessHelper = new ChanServAccessHelper($accessRepo, $levelRepo);

        $channelServiceActions = $this->createMock(ChannelServiceActionsPort::class);
        $channelServiceActions->expects(self::never())->method('kickFromChannel');

        $subscriber = $this->createSubscriber(
            $channelRepo,
            $levelRepo,
            $nickRepo,
            $this->createStub(ChannelLookupPort::class),
            $this->createStub(NetworkUserLookupPort::class),
            $channelServiceActions,
            $accessHelper,
        );

        $event = new UserJoinedChannelEvent(new Uid('UID1'), new ChannelName('#test'), ChannelMemberRole::None);
        $subscriber->onUserJoined($event);
    }

    #[Test]
    public function doesNothingWhenUserNotFound(): void
    {
        $channel = $this->createStub(RegisteredChannel::class);
        $channel->method('getId')->willReturn(1);

        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channelRepo->method('findByChannelName')->willReturn($channel);

        $userLookup = $this->createStub(NetworkUserLookupPort::class);
        $userLookup->method('findByUid')->willReturn(null);

        $levelRepo = $this->createStub(ChannelLevelRepositoryInterface::class);
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $accessRepo = $this->createStub(ChannelAccessRepositoryInterface::class);
        $accessHelper = new ChanServAccessHelper($accessRepo, $levelRepo);

        $channelServiceActions = $this->createMock(ChannelServiceActionsPort::class);
        $channelServiceActions->expects(self::never())->method('kickFromChannel');

        $subscriber = $this->createSubscriber(
            $channelRepo,
            $levelRepo,
            $nickRepo,
            $this->createStub(ChannelLookupPort::class),
            $userLookup,
            $channelServiceActions,
            $accessHelper,
        );

        $event = new UserJoinedChannelEvent(new Uid('UID1'), new ChannelName('#test'), ChannelMemberRole::None);
        $subscriber->onUserJoined($event);
    }

    #[Test]
    public function usesDefaultNojoinLevelWhenNotSet(): void
    {
        $channel = $this->createStub(RegisteredChannel::class);
        $channel->method('getId')->willReturn(1);
        $channel->method('isFounder')->willReturn(false);

        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channelRepo->method('findByChannelName')->willReturn($channel);

        $levelRepo = $this->createStub(ChannelLevelRepositoryInterface::class);
        $levelRepo->method('findByChannelAndKey')->willReturn(null);

        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepo->method('findByNick')->willReturn(null);

        $accessRepo = $this->createStub(ChannelAccessRepositoryInterface::class);
        $accessHelper = new ChanServAccessHelper($accessRepo, $levelRepo);

        $userView = new SenderView(
            'UID1',
            'User',
            'user',
            'user.isp.com',
            'user.isp.com',
            '192.168.1.1',
            false,
            false,
            '001',
            'user.isp.com',
        );

        $userLookup = $this->createStub(NetworkUserLookupPort::class);
        $userLookup->method('findByUid')->willReturn($userView);

        $channelServiceActions = $this->createMock(ChannelServiceActionsPort::class);
        $channelServiceActions->expects(self::never())->method('kickFromChannel');

        $subscriber = $this->createSubscriber(
            $channelRepo,
            $levelRepo,
            $nickRepo,
            $this->createStub(ChannelLookupPort::class),
            $userLookup,
            $channelServiceActions,
            $accessHelper,
        );

        $event = new UserJoinedChannelEvent(new Uid('UID1'), new ChannelName('#test'), ChannelMemberRole::None);
        $subscriber->onUserJoined($event);
    }

    #[Test]
    public function getSubscribedEventsReturnsCorrectEvents(): void
    {
        $expected = [
            UserJoinedChannelEvent::class => ['onUserJoined', 10],
            NetworkSyncCompleteEvent::class => ['onSyncComplete', 10],
        ];
        self::assertSame($expected, ChanServNojoinEnforceSubscriber::getSubscribedEvents());
    }

    #[Test]
    public function onSyncCompleteEnforcesNojoinOnAllChannels(): void
    {
        $channel = $this->createStub(RegisteredChannel::class);
        $channel->method('getId')->willReturn(1);
        $channel->method('getName')->willReturn('#test');
        $channel->method('isFounder')->willReturn(false);

        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channelRepo->method('listAll')->willReturn([$channel]);

        $nojoinLevel = new ChannelLevel(1, ChannelLevel::KEY_NOJOIN, 50);
        $levelRepo = $this->createStub(ChannelLevelRepositoryInterface::class);
        $levelRepo->method('findByChannelAndKey')->willReturn($nojoinLevel);

        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepo->method('findByNick')->willReturn(null);

        $accessRepo = $this->createStub(ChannelAccessRepositoryInterface::class);
        $accessHelper = new ChanServAccessHelper($accessRepo, $levelRepo);

        $userView = new SenderView(
            'UID1',
            'User',
            'user',
            'user.isp.com',
            'user.isp.com',
            '192.168.1.1',
            false,
            false,
            '001',
            'user.isp.com',
        );

        $userLookup = $this->createStub(NetworkUserLookupPort::class);
        $userLookup->method('findByUid')->willReturn($userView);

        $channelView = new ChannelView('#test', '+nt', null, 1, [['uid' => 'UID1', 'roleLetter' => '']]);
        $channelLookup = $this->createStub(ChannelLookupPort::class);
        $channelLookup->method('findByChannelName')->willReturn($channelView);

        $kicked = [];
        $channelServiceActions = $this->createStub(ChannelServiceActionsPort::class);
        $channelServiceActions->method('kickFromChannel')
            ->willReturnCallback(static function (string $channel, string $uid, string $reason) use (&$kicked): void {
                $kicked[] = ['channel' => $channel, 'uid' => $uid, 'reason' => $reason];
            });

        $subscriber = $this->createSubscriber(
            $channelRepo,
            $levelRepo,
            $nickRepo,
            $channelLookup,
            $userLookup,
            $channelServiceActions,
            $accessHelper,
        );

        $connection = $this->createStub(ConnectionInterface::class);
        $event = new NetworkSyncCompleteEvent($connection, '001');
        $subscriber->onSyncComplete($event);

        self::assertCount(1, $kicked);
        self::assertSame('#test', $kicked[0]['channel']);
        self::assertSame('UID1', $kicked[0]['uid']);
        self::assertSame('NOJOIN level restriction', $kicked[0]['reason']);
    }

    #[Test]
    public function onSyncCompleteSkipsChannelsWithNojoinMinusOne(): void
    {
        $channel = $this->createStub(RegisteredChannel::class);
        $channel->method('getId')->willReturn(1);
        $channel->method('getName')->willReturn('#test');

        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channelRepo->method('listAll')->willReturn([$channel]);

        $nojoinLevel = new ChannelLevel(1, ChannelLevel::KEY_NOJOIN, -1);
        $levelRepo = $this->createStub(ChannelLevelRepositoryInterface::class);
        $levelRepo->method('findByChannelAndKey')->willReturn($nojoinLevel);

        $accessRepo = $this->createStub(ChannelAccessRepositoryInterface::class);
        $accessHelper = new ChanServAccessHelper($accessRepo, $levelRepo);

        $channelServiceActions = $this->createMock(ChannelServiceActionsPort::class);
        $channelServiceActions->expects(self::never())->method('kickFromChannel');

        $subscriber = $this->createSubscriber(
            $channelRepo,
            $levelRepo,
            $this->createStub(RegisteredNickRepositoryInterface::class),
            $this->createStub(ChannelLookupPort::class),
            $this->createStub(NetworkUserLookupPort::class),
            $channelServiceActions,
            $accessHelper,
        );

        $connection = $this->createStub(ConnectionInterface::class);
        $event = new NetworkSyncCompleteEvent($connection, '001');
        $subscriber->onSyncComplete($event);
    }

    #[Test]
    public function onSyncCompleteSkipsOperUsers(): void
    {
        $channel = $this->createStub(RegisteredChannel::class);
        $channel->method('getId')->willReturn(1);
        $channel->method('getName')->willReturn('#test');

        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channelRepo->method('listAll')->willReturn([$channel]);

        $nojoinLevel = new ChannelLevel(1, ChannelLevel::KEY_NOJOIN, 50);
        $levelRepo = $this->createStub(ChannelLevelRepositoryInterface::class);
        $levelRepo->method('findByChannelAndKey')->willReturn($nojoinLevel);

        $accessRepo = $this->createStub(ChannelAccessRepositoryInterface::class);
        $accessHelper = new ChanServAccessHelper($accessRepo, $levelRepo);

        $userView = new SenderView(
            'UID1',
            'Oper',
            'oper',
            'oper.isp.com',
            'oper.isp.com',
            '192.168.1.1',
            false,
            true,
            '001',
            'oper.isp.com',
        );

        $userLookup = $this->createStub(NetworkUserLookupPort::class);
        $userLookup->method('findByUid')->willReturn($userView);

        $channelView = new ChannelView('#test', '+nt', null, 1, [['uid' => 'UID1', 'roleLetter' => '']]);
        $channelLookup = $this->createStub(ChannelLookupPort::class);
        $channelLookup->method('findByChannelName')->willReturn($channelView);

        $channelServiceActions = $this->createMock(ChannelServiceActionsPort::class);
        $channelServiceActions->expects(self::never())->method('kickFromChannel');

        $subscriber = $this->createSubscriber(
            $channelRepo,
            $levelRepo,
            $this->createStub(RegisteredNickRepositoryInterface::class),
            $channelLookup,
            $userLookup,
            $channelServiceActions,
            $accessHelper,
        );

        $connection = $this->createStub(ConnectionInterface::class);
        $event = new NetworkSyncCompleteEvent($connection, '001');
        $subscriber->onSyncComplete($event);
    }

    #[Test]
    public function onSyncCompleteSkipsChanServUser(): void
    {
        $channel = $this->createStub(RegisteredChannel::class);
        $channel->method('getId')->willReturn(1);
        $channel->method('getName')->willReturn('#test');

        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channelRepo->method('listAll')->willReturn([$channel]);

        $nojoinLevel = new ChannelLevel(1, ChannelLevel::KEY_NOJOIN, 50);
        $levelRepo = $this->createStub(ChannelLevelRepositoryInterface::class);
        $levelRepo->method('findByChannelAndKey')->willReturn($nojoinLevel);

        $accessRepo = $this->createStub(ChannelAccessRepositoryInterface::class);
        $accessHelper = new ChanServAccessHelper($accessRepo, $levelRepo);

        $userView = new SenderView(
            'ChanServUID',
            'ChanServ',
            'ChanServ',
            'services.local',
            'services.local',
            '192.168.1.1',
            false,
            false,
            '001',
            'services.local',
        );

        $userLookup = $this->createStub(NetworkUserLookupPort::class);
        $userLookup->method('findByUid')->willReturn($userView);

        $channelView = new ChannelView('#test', '+nt', null, 1, [['uid' => 'ChanServUID', 'roleLetter' => '']]);
        $channelLookup = $this->createStub(ChannelLookupPort::class);
        $channelLookup->method('findByChannelName')->willReturn($channelView);

        $channelServiceActions = $this->createMock(ChannelServiceActionsPort::class);
        $channelServiceActions->expects(self::never())->method('kickFromChannel');

        $subscriber = $this->createSubscriber(
            $channelRepo,
            $levelRepo,
            $this->createStub(RegisteredNickRepositoryInterface::class),
            $channelLookup,
            $userLookup,
            $channelServiceActions,
            $accessHelper,
        );

        $connection = $this->createStub(ConnectionInterface::class);
        $event = new NetworkSyncCompleteEvent($connection, '001');
        $subscriber->onSyncComplete($event);
    }

    #[Test]
    public function onSyncCompleteSkipsChannelWhenChannelViewNotFound(): void
    {
        $channel = $this->createStub(RegisteredChannel::class);
        $channel->method('getId')->willReturn(1);
        $channel->method('getName')->willReturn('#test');

        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channelRepo->method('listAll')->willReturn([$channel]);

        $nojoinLevel = new ChannelLevel(1, ChannelLevel::KEY_NOJOIN, 50);
        $levelRepo = $this->createStub(ChannelLevelRepositoryInterface::class);
        $levelRepo->method('findByChannelAndKey')->willReturn($nojoinLevel);

        $channelLookup = $this->createStub(ChannelLookupPort::class);
        $channelLookup->method('findByChannelName')->willReturn(null);

        $accessRepo = $this->createStub(ChannelAccessRepositoryInterface::class);
        $accessHelper = new ChanServAccessHelper($accessRepo, $levelRepo);

        $channelServiceActions = $this->createMock(ChannelServiceActionsPort::class);
        $channelServiceActions->expects(self::never())->method('kickFromChannel');

        $subscriber = $this->createSubscriber(
            $channelRepo,
            $levelRepo,
            $this->createStub(RegisteredNickRepositoryInterface::class),
            $channelLookup,
            $this->createStub(NetworkUserLookupPort::class),
            $channelServiceActions,
            $accessHelper,
        );

        $connection = $this->createStub(ConnectionInterface::class);
        $event = new NetworkSyncCompleteEvent($connection, '001');
        $subscriber->onSyncComplete($event);
    }

    #[Test]
    public function kicksUserWithAccessLevelBelowNojoin(): void
    {
        $channel = $this->createStub(RegisteredChannel::class);
        $channel->method('getId')->willReturn(1);
        $channel->method('isFounder')->willReturn(false);

        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channelRepo->method('findByChannelName')->willReturn($channel);

        $nojoinLevel = new ChannelLevel(1, ChannelLevel::KEY_NOJOIN, 100);
        $levelRepo = $this->createStub(ChannelLevelRepositoryInterface::class);
        $levelRepo->method('findByChannelAndKey')->willReturn($nojoinLevel);

        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepo->method('findByNick')->willReturn(null);

        $accessRepo = $this->createStub(ChannelAccessRepositoryInterface::class);
        $accessHelper = new ChanServAccessHelper($accessRepo, $levelRepo);

        $userView = new SenderView(
            'UID1',
            'User',
            'user',
            'user.isp.com',
            'user.isp.com',
            '192.168.1.1',
            false,
            false,
            '001',
            'user.isp.com',
        );

        $userLookup = $this->createStub(NetworkUserLookupPort::class);
        $userLookup->method('findByUid')->willReturn($userView);

        $kicked = [];
        $channelServiceActions = $this->createMock(ChannelServiceActionsPort::class);
        $channelServiceActions->expects(self::once())->method('kickFromChannel')
            ->willReturnCallback(static function (string $channel, string $uid, string $reason) use (&$kicked): void {
                $kicked[] = ['channel' => $channel, 'uid' => $uid, 'reason' => $reason];
            });

        $subscriber = $this->createSubscriber(
            $channelRepo,
            $levelRepo,
            $nickRepo,
            $this->createStub(ChannelLookupPort::class),
            $userLookup,
            $channelServiceActions,
            $accessHelper,
        );

        $event = new UserJoinedChannelEvent(new Uid('UID1'), new ChannelName('#test'), ChannelMemberRole::None);
        $subscriber->onUserJoined($event);

        self::assertCount(1, $kicked);
    }

    #[Test]
    public function onSyncCompleteSkipsMemberWithEmptyUid(): void
    {
        $channel = $this->createStub(RegisteredChannel::class);
        $channel->method('getId')->willReturn(1);
        $channel->method('getName')->willReturn('#test');

        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channelRepo->method('listAll')->willReturn([$channel]);

        $nojoinLevel = new ChannelLevel(1, ChannelLevel::KEY_NOJOIN, 50);
        $levelRepo = $this->createStub(ChannelLevelRepositoryInterface::class);
        $levelRepo->method('findByChannelAndKey')->willReturn($nojoinLevel);

        $accessRepo = $this->createStub(ChannelAccessRepositoryInterface::class);
        $accessHelper = new ChanServAccessHelper($accessRepo, $levelRepo);

        $channelView = new ChannelView('#test', '+nt', null, 1, [['roleLetter' => '']]);
        $channelLookup = $this->createStub(ChannelLookupPort::class);
        $channelLookup->method('findByChannelName')->willReturn($channelView);

        $channelServiceActions = $this->createMock(ChannelServiceActionsPort::class);
        $channelServiceActions->expects(self::never())->method('kickFromChannel');

        $subscriber = $this->createSubscriber(
            $channelRepo,
            $levelRepo,
            $this->createStub(RegisteredNickRepositoryInterface::class),
            $channelLookup,
            $this->createStub(NetworkUserLookupPort::class),
            $channelServiceActions,
            $accessHelper,
        );

        $connection = $this->createStub(ConnectionInterface::class);
        $event = new NetworkSyncCompleteEvent($connection, '001');
        $subscriber->onSyncComplete($event);
    }

    #[Test]
    public function usesUserLanguageForKickReason(): void
    {
        $registeredNick = $this->createStub(RegisteredNick::class);
        $registeredNick->method('getId')->willReturn(10);
        $registeredNick->method('getLanguage')->willReturn('es');

        $channel = $this->createStub(RegisteredChannel::class);
        $channel->method('getId')->willReturn(1);
        $channel->method('isFounder')->willReturn(false);

        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channelRepo->method('findByChannelName')->willReturn($channel);

        $nojoinLevel = new ChannelLevel(1, ChannelLevel::KEY_NOJOIN, 50);
        $levelRepo = $this->createStub(ChannelLevelRepositoryInterface::class);
        $levelRepo->method('findByChannelAndKey')->willReturn($nojoinLevel);

        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepo->method('findByNick')->willReturn($registeredNick);

        $channelAccess = $this->createStub(ChannelAccess::class);
        $channelAccess->method('getLevel')->willReturn(10);

        $accessRepo = $this->createStub(ChannelAccessRepositoryInterface::class);
        $accessRepo->method('findByChannelAndNick')->willReturn($channelAccess);
        $accessHelper = new ChanServAccessHelper($accessRepo, $levelRepo);

        $userView = new SenderView(
            'UID1',
            'User',
            'user',
            'user.isp.com',
            'user.isp.com',
            '192.168.1.1',
            true,
            false,
            '001',
            'user.isp.com',
        );

        $userLookup = $this->createStub(NetworkUserLookupPort::class);
        $userLookup->method('findByUid')->willReturn($userView);

        $kicked = [];
        $channelServiceActions = $this->createMock(ChannelServiceActionsPort::class);
        $channelServiceActions->expects(self::once())->method('kickFromChannel')
            ->willReturnCallback(static function (string $channel, string $uid, string $reason) use (&$kicked): void {
                $kicked[] = ['channel' => $channel, 'uid' => $uid, 'reason' => $reason];
            });

        $translator = $this->createMock(TranslatorInterface::class);
        $translator->expects(self::once())->method('trans')
            ->with('nojoin.reason', [], 'chanserv', 'es')
            ->willReturn('Restricción de nivel NOJOIN');

        $subscriber = new ChanServNojoinEnforceSubscriber(
            $channelRepo,
            $levelRepo,
            $nickRepo,
            $this->createStub(ChannelLookupPort::class),
            $userLookup,
            $channelServiceActions,
            $accessHelper,
            $translator,
            'ChanServ',
            'en',
            new NullLogger(),
        );

        $event = new UserJoinedChannelEvent(new Uid('UID1'), new ChannelName('#test'), ChannelMemberRole::None);
        $subscriber->onUserJoined($event);

        self::assertCount(1, $kicked);
        self::assertSame('Restricción de nivel NOJOIN', $kicked[0]['reason']);
    }

    #[Test]
    public function onSyncCompleteUsesUserLanguageForKickReason(): void
    {
        $registeredNick = $this->createStub(RegisteredNick::class);
        $registeredNick->method('getId')->willReturn(10);
        $registeredNick->method('getLanguage')->willReturn('es');

        $channel = $this->createStub(RegisteredChannel::class);
        $channel->method('getId')->willReturn(1);
        $channel->method('getName')->willReturn('#test');
        $channel->method('isFounder')->willReturn(false);

        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channelRepo->method('listAll')->willReturn([$channel]);

        $nojoinLevel = new ChannelLevel(1, ChannelLevel::KEY_NOJOIN, 100);
        $levelRepo = $this->createStub(ChannelLevelRepositoryInterface::class);
        $levelRepo->method('findByChannelAndKey')->willReturn($nojoinLevel);

        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepo->method('findByNick')->willReturn($registeredNick);

        $channelAccess = $this->createStub(ChannelAccess::class);
        $channelAccess->method('getLevel')->willReturn(10);

        $accessRepo = $this->createStub(ChannelAccessRepositoryInterface::class);
        $accessRepo->method('findByChannelAndNick')->willReturn($channelAccess);
        $accessHelper = new ChanServAccessHelper($accessRepo, $levelRepo);

        $userView = new SenderView(
            'UID1',
            'User',
            'user',
            'user.isp.com',
            'user.isp.com',
            '192.168.1.1',
            true,
            false,
            '001',
            'user.isp.com',
        );

        $userLookup = $this->createStub(NetworkUserLookupPort::class);
        $userLookup->method('findByUid')->willReturn($userView);

        $channelView = new ChannelView('#test', '+nt', null, 1, [['uid' => 'UID1', 'roleLetter' => '']]);
        $channelLookup = $this->createStub(ChannelLookupPort::class);
        $channelLookup->method('findByChannelName')->willReturn($channelView);

        $kicked = [];
        $channelServiceActions = $this->createStub(ChannelServiceActionsPort::class);
        $channelServiceActions->method('kickFromChannel')
            ->willReturnCallback(static function (string $channel, string $uid, string $reason) use (&$kicked): void {
                $kicked[] = ['channel' => $channel, 'uid' => $uid, 'reason' => $reason];
            });

        $translator = $this->createMock(TranslatorInterface::class);
        $translator->expects(self::once())->method('trans')
            ->with('nojoin.reason', [], 'chanserv', 'es')
            ->willReturn('Restricción de nivel NOJOIN');

        $subscriber = new ChanServNojoinEnforceSubscriber(
            $channelRepo,
            $levelRepo,
            $nickRepo,
            $channelLookup,
            $userLookup,
            $channelServiceActions,
            $accessHelper,
            $translator,
            'ChanServ',
            'en',
            new NullLogger(),
        );

        $connection = $this->createStub(ConnectionInterface::class);
        $event = new NetworkSyncCompleteEvent($connection, '001');
        $subscriber->onSyncComplete($event);

        self::assertCount(1, $kicked);
        self::assertSame('Restricción de nivel NOJOIN', $kicked[0]['reason']);
    }

    #[Test]
    public function kicksRegisteredNickButNotIdentifiedUser(): void
    {
        $registeredNick = $this->createStub(RegisteredNick::class);
        $registeredNick->method('getId')->willReturn(10);
        $registeredNick->method('getLanguage')->willReturn('en');

        $channel = $this->createStub(RegisteredChannel::class);
        $channel->method('getId')->willReturn(1);
        $channel->method('isFounder')->willReturn(false);

        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channelRepo->method('findByChannelName')->willReturn($channel);

        $nojoinLevel = new ChannelLevel(1, ChannelLevel::KEY_NOJOIN, 0);
        $levelRepo = $this->createStub(ChannelLevelRepositoryInterface::class);
        $levelRepo->method('findByChannelAndKey')->willReturn($nojoinLevel);

        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepo->method('findByNick')->willReturn($registeredNick);

        $accessRepo = $this->createStub(ChannelAccessRepositoryInterface::class);
        $accessHelper = new ChanServAccessHelper($accessRepo, $levelRepo);

        $userView = new SenderView(
            'UID1',
            'User',
            'user',
            'user.isp.com',
            'user.isp.com',
            '192.168.1.1',
            false,
            false,
            '001',
            'user.isp.com',
        );

        $userLookup = $this->createStub(NetworkUserLookupPort::class);
        $userLookup->method('findByUid')->willReturn($userView);

        $kicked = [];
        $channelServiceActions = $this->createMock(ChannelServiceActionsPort::class);
        $channelServiceActions->expects(self::once())->method('kickFromChannel')
            ->willReturnCallback(static function (string $channel, string $uid, string $reason) use (&$kicked): void {
                $kicked[] = ['channel' => $channel, 'uid' => $uid, 'reason' => $reason];
            });

        $subscriber = $this->createSubscriber(
            $channelRepo,
            $levelRepo,
            $nickRepo,
            $this->createStub(ChannelLookupPort::class),
            $userLookup,
            $channelServiceActions,
            $accessHelper,
        );

        $event = new UserJoinedChannelEvent(new Uid('UID1'), new ChannelName('#test'), ChannelMemberRole::None);
        $subscriber->onUserJoined($event);

        self::assertCount(1, $kicked);
    }

    #[Test]
    public function doesNotKickIdentifiedUserWithNojoinMinusOne(): void
    {
        $registeredNick = $this->createStub(RegisteredNick::class);
        $registeredNick->method('getId')->willReturn(10);

        $channel = $this->createStub(RegisteredChannel::class);
        $channel->method('getId')->willReturn(1);
        $channel->method('isFounder')->willReturn(false);

        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channelRepo->method('findByChannelName')->willReturn($channel);

        $nojoinLevel = new ChannelLevel(1, ChannelLevel::KEY_NOJOIN, -1);
        $levelRepo = $this->createStub(ChannelLevelRepositoryInterface::class);
        $levelRepo->method('findByChannelAndKey')->willReturn($nojoinLevel);

        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepo->method('findByNick')->willReturn($registeredNick);

        $accessRepo = $this->createStub(ChannelAccessRepositoryInterface::class);
        $accessHelper = new ChanServAccessHelper($accessRepo, $levelRepo);

        $userView = new SenderView(
            'UID1',
            'User',
            'user',
            'user.isp.com',
            'user.isp.com',
            '192.168.1.1',
            true,
            false,
            '001',
            'user.isp.com',
        );

        $userLookup = $this->createStub(NetworkUserLookupPort::class);
        $userLookup->method('findByUid')->willReturn($userView);

        $channelServiceActions = $this->createMock(ChannelServiceActionsPort::class);
        $channelServiceActions->expects(self::never())->method('kickFromChannel');

        $subscriber = $this->createSubscriber(
            $channelRepo,
            $levelRepo,
            $nickRepo,
            $this->createStub(ChannelLookupPort::class),
            $userLookup,
            $channelServiceActions,
            $accessHelper,
        );

        $event = new UserJoinedChannelEvent(new Uid('UID1'), new ChannelName('#test'), ChannelMemberRole::None);
        $subscriber->onUserJoined($event);
    }

    #[Test]
    public function doesNotKickIdentifiedUserWithLevelZeroAndNojoinZero(): void
    {
        $registeredNick = $this->createStub(RegisteredNick::class);
        $registeredNick->method('getId')->willReturn(10);

        $channel = $this->createStub(RegisteredChannel::class);
        $channel->method('getId')->willReturn(1);
        $channel->method('isFounder')->willReturn(false);

        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channelRepo->method('findByChannelName')->willReturn($channel);

        $nojoinLevel = new ChannelLevel(1, ChannelLevel::KEY_NOJOIN, 0);
        $levelRepo = $this->createStub(ChannelLevelRepositoryInterface::class);
        $levelRepo->method('findByChannelAndKey')->willReturn($nojoinLevel);

        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepo->method('findByNick')->willReturn($registeredNick);

        $accessRepo = $this->createStub(ChannelAccessRepositoryInterface::class);
        $accessRepo->method('findByChannelAndNick')->willReturn(null);
        $accessHelper = new ChanServAccessHelper($accessRepo, $levelRepo);

        $userView = new SenderView(
            'UID1',
            'User',
            'user',
            'user.isp.com',
            'user.isp.com',
            '192.168.1.1',
            true,
            false,
            '001',
            'user.isp.com',
        );

        $userLookup = $this->createStub(NetworkUserLookupPort::class);
        $userLookup->method('findByUid')->willReturn($userView);

        $channelServiceActions = $this->createMock(ChannelServiceActionsPort::class);
        $channelServiceActions->expects(self::never())->method('kickFromChannel');

        $subscriber = $this->createSubscriber(
            $channelRepo,
            $levelRepo,
            $nickRepo,
            $this->createStub(ChannelLookupPort::class),
            $userLookup,
            $channelServiceActions,
            $accessHelper,
        );

        $event = new UserJoinedChannelEvent(new Uid('UID1'), new ChannelName('#test'), ChannelMemberRole::None);
        $subscriber->onUserJoined($event);
    }
}
