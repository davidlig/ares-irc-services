<?php

declare(strict_types=1);

namespace App\Tests\ChanServ\Adapter\In\Event;

use App\Application\Port\ChannelServiceActionsPort;
use App\ChanServ\Adapter\In\Event\ChanServNojoinEnforceSubscriber;
use App\ChanServ\Application\Model\ChanAccountView;
use App\ChanServ\Application\Port\Out\ChannelAccessRepositoryInterface;
use App\ChanServ\Application\Port\Out\ChannelLevelRepositoryInterface;
use App\ChanServ\Application\Port\Out\ChanUserAccountPort;
use App\ChanServ\Application\Port\Out\RegisteredChannelRepositoryInterface;
use App\ChanServ\Application\Service\ChanServAccessHelper;
use App\ChanServ\Domain\Entity\ChannelAccess;
use App\ChanServ\Domain\Entity\ChannelLevel;
use App\ChanServ\Domain\Entity\RegisteredChannel;
use App\Irc\Application\Port\In\ChannelLookupPort;
use App\Irc\Application\Port\In\ChannelView;
use App\Irc\Application\Port\In\NetworkUserLookupPort;
use App\Irc\Application\Port\In\SenderView;
use App\Irc\Application\PublishedEvent\NetworkSynchronizationCompletedEvent;
use App\Irc\Application\PublishedEvent\UserJoinedChannelEvent;
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
        ChanUserAccountPort $nickRepo,
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

        $nickRepo = $this->createStub(ChanUserAccountPort::class);
        $nickRepo->method('findAccountByNick')->willReturn(null);

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

        $event = new UserJoinedChannelEvent('UID1', '#test');
        $subscriber->onUserJoined($event);

        self::assertCount(1, $kicked);
        self::assertSame('#test', $kicked[0]['channel']);
        self::assertSame('UID1', $kicked[0]['uid']);
        self::assertSame('NOJOIN level restriction', $kicked[0]['reason']);
    }

    #[Test]
    public function doesNotKickUserWithLevelAboveNojoin(): void
    {
        $registeredNick = new ChanAccountView(10, 'TestNick', 'en');

        $channel = $this->createStub(RegisteredChannel::class);
        $channel->method('getId')->willReturn(1);
        $channel->method('isFounder')->willReturn(false);

        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channelRepo->method('findByChannelName')->willReturn($channel);

        $nojoinLevel = new ChannelLevel(1, ChannelLevel::KEY_NOJOIN, 50);
        $levelRepo = $this->createStub(ChannelLevelRepositoryInterface::class);
        $levelRepo->method('findByChannelAndKey')->willReturn($nojoinLevel);

        $nickRepo = $this->createStub(ChanUserAccountPort::class);
        $nickRepo->method('findAccountByNick')->willReturn($registeredNick);

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

        $event = new UserJoinedChannelEvent('UID1', '#test');
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

        $nickRepo = $this->createStub(ChanUserAccountPort::class);

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

        $event = new UserJoinedChannelEvent('UID1', '#test');
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

        $nickRepo = $this->createStub(ChanUserAccountPort::class);

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

        $event = new UserJoinedChannelEvent('UID1', '#test');
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

        $nickRepo = $this->createStub(ChanUserAccountPort::class);

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

        $event = new UserJoinedChannelEvent('UID1', '#test');
        $subscriber->onUserJoined($event);
    }

    #[Test]
    public function doesNothingWhenChannelNotRegistered(): void
    {
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channelRepo->method('findByChannelName')->willReturn(null);

        $levelRepo = $this->createStub(ChannelLevelRepositoryInterface::class);
        $nickRepo = $this->createStub(ChanUserAccountPort::class);
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

        $event = new UserJoinedChannelEvent('UID1', '#test');
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
        $nickRepo = $this->createStub(ChanUserAccountPort::class);
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

        $event = new UserJoinedChannelEvent('UID1', '#test');
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

        $nickRepo = $this->createStub(ChanUserAccountPort::class);
        $nickRepo->method('findAccountByNick')->willReturn(null);

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

        $event = new UserJoinedChannelEvent('UID1', '#test');
        $subscriber->onUserJoined($event);
    }

    #[Test]
    public function legacyJoinEntryPointDelegatesToThePublishedEventHandler(): void
    {
        $channelRepository = $this->createMock(RegisteredChannelRepositoryInterface::class);
        $channelRepository->expects(self::once())->method('findByChannelName')->with('#test')->willReturn(null);
        $levelRepository = $this->createStub(ChannelLevelRepositoryInterface::class);
        $accessHelper = new ChanServAccessHelper($this->createStub(ChannelAccessRepositoryInterface::class), $levelRepository);
        $subscriber = $this->createSubscriber(
            $channelRepository,
            $levelRepository,
            $this->createStub(ChanUserAccountPort::class),
            $this->createStub(ChannelLookupPort::class),
            $this->createStub(NetworkUserLookupPort::class),
            $this->createStub(ChannelServiceActionsPort::class),
            $accessHelper,
        );

        $subscriber->onUserJoinedChannel(new UserJoinedChannelEvent('UID1', '#test'));
    }

    #[Test]
    public function getSubscribedEventsReturnsCorrectEvents(): void
    {
        $expected = [
            UserJoinedChannelEvent::class => ['onUserJoined', 10],
            NetworkSynchronizationCompletedEvent::class => ['onSyncComplete', 10],
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

        $nickRepo = $this->createStub(ChanUserAccountPort::class);
        $nickRepo->method('findAccountByNick')->willReturn(null);

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

        $event = new NetworkSynchronizationCompletedEvent('001');
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
            $this->createStub(ChanUserAccountPort::class),
            $this->createStub(ChannelLookupPort::class),
            $this->createStub(NetworkUserLookupPort::class),
            $channelServiceActions,
            $accessHelper,
        );

        $event = new NetworkSynchronizationCompletedEvent('001');
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
            $this->createStub(ChanUserAccountPort::class),
            $channelLookup,
            $userLookup,
            $channelServiceActions,
            $accessHelper,
        );

        $event = new NetworkSynchronizationCompletedEvent('001');
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
            $this->createStub(ChanUserAccountPort::class),
            $channelLookup,
            $userLookup,
            $channelServiceActions,
            $accessHelper,
        );

        $event = new NetworkSynchronizationCompletedEvent('001');
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
            $this->createStub(ChanUserAccountPort::class),
            $channelLookup,
            $this->createStub(NetworkUserLookupPort::class),
            $channelServiceActions,
            $accessHelper,
        );

        $event = new NetworkSynchronizationCompletedEvent('001');
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

        $nickRepo = $this->createStub(ChanUserAccountPort::class);
        $nickRepo->method('findAccountByNick')->willReturn(null);

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

        $event = new UserJoinedChannelEvent('UID1', '#test');
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

        $channelView = new ChannelView('#test', '+nt', null, 1, [['uid' => '', 'roleLetter' => '']]);
        $channelLookup = $this->createStub(ChannelLookupPort::class);
        $channelLookup->method('findByChannelName')->willReturn($channelView);

        $channelServiceActions = $this->createMock(ChannelServiceActionsPort::class);
        $channelServiceActions->expects(self::never())->method('kickFromChannel');

        $subscriber = $this->createSubscriber(
            $channelRepo,
            $levelRepo,
            $this->createStub(ChanUserAccountPort::class),
            $channelLookup,
            $this->createStub(NetworkUserLookupPort::class),
            $channelServiceActions,
            $accessHelper,
        );

        $event = new NetworkSynchronizationCompletedEvent('001');
        $subscriber->onSyncComplete($event);
    }

    #[Test]
    public function usesUserLanguageForKickReason(): void
    {
        $registeredNick = new ChanAccountView(10, 'TestNick', 'es');

        $channel = $this->createStub(RegisteredChannel::class);
        $channel->method('getId')->willReturn(1);
        $channel->method('isFounder')->willReturn(false);

        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channelRepo->method('findByChannelName')->willReturn($channel);

        $nojoinLevel = new ChannelLevel(1, ChannelLevel::KEY_NOJOIN, 50);
        $levelRepo = $this->createStub(ChannelLevelRepositoryInterface::class);
        $levelRepo->method('findByChannelAndKey')->willReturn($nojoinLevel);

        $nickRepo = $this->createStub(ChanUserAccountPort::class);
        $nickRepo->method('findAccountByNick')->willReturn($registeredNick);

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

        $event = new UserJoinedChannelEvent('UID1', '#test');
        $subscriber->onUserJoined($event);

        self::assertCount(1, $kicked);
        self::assertSame('Restricción de nivel NOJOIN', $kicked[0]['reason']);
    }

    #[Test]
    public function onSyncCompleteUsesUserLanguageForKickReason(): void
    {
        $registeredNick = new ChanAccountView(10, 'TestNick', 'es');

        $channel = $this->createStub(RegisteredChannel::class);
        $channel->method('getId')->willReturn(1);
        $channel->method('getName')->willReturn('#test');
        $channel->method('isFounder')->willReturn(false);

        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channelRepo->method('listAll')->willReturn([$channel]);

        $nojoinLevel = new ChannelLevel(1, ChannelLevel::KEY_NOJOIN, 100);
        $levelRepo = $this->createStub(ChannelLevelRepositoryInterface::class);
        $levelRepo->method('findByChannelAndKey')->willReturn($nojoinLevel);

        $nickRepo = $this->createStub(ChanUserAccountPort::class);
        $nickRepo->method('findAccountByNick')->willReturn($registeredNick);

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

        $event = new NetworkSynchronizationCompletedEvent('001');
        $subscriber->onSyncComplete($event);

        self::assertCount(1, $kicked);
        self::assertSame('Restricción de nivel NOJOIN', $kicked[0]['reason']);
    }

    #[Test]
    public function kicksRegisteredNickButNotIdentifiedUser(): void
    {
        $registeredNick = new ChanAccountView(10, 'TestNick', 'en');

        $channel = $this->createStub(RegisteredChannel::class);
        $channel->method('getId')->willReturn(1);
        $channel->method('isFounder')->willReturn(false);

        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channelRepo->method('findByChannelName')->willReturn($channel);

        $nojoinLevel = new ChannelLevel(1, ChannelLevel::KEY_NOJOIN, 0);
        $levelRepo = $this->createStub(ChannelLevelRepositoryInterface::class);
        $levelRepo->method('findByChannelAndKey')->willReturn($nojoinLevel);

        $nickRepo = $this->createStub(ChanUserAccountPort::class);
        $nickRepo->method('findAccountByNick')->willReturn($registeredNick);

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

        $event = new UserJoinedChannelEvent('UID1', '#test');
        $subscriber->onUserJoined($event);

        self::assertCount(1, $kicked);
    }

    #[Test]
    public function doesNotKickIdentifiedUserWithNojoinMinusOne(): void
    {
        $registeredNick = new ChanAccountView(10, 'TestNick', 'en');

        $channel = $this->createStub(RegisteredChannel::class);
        $channel->method('getId')->willReturn(1);
        $channel->method('isFounder')->willReturn(false);

        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channelRepo->method('findByChannelName')->willReturn($channel);

        $nojoinLevel = new ChannelLevel(1, ChannelLevel::KEY_NOJOIN, -1);
        $levelRepo = $this->createStub(ChannelLevelRepositoryInterface::class);
        $levelRepo->method('findByChannelAndKey')->willReturn($nojoinLevel);

        $nickRepo = $this->createStub(ChanUserAccountPort::class);
        $nickRepo->method('findAccountByNick')->willReturn($registeredNick);

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

        $event = new UserJoinedChannelEvent('UID1', '#test');
        $subscriber->onUserJoined($event);
    }

    #[Test]
    public function onUserJoinedSkipsSuspendedChannel(): void
    {
        $channel = $this->createStub(RegisteredChannel::class);
        $channel->method('isSuspended')->willReturn(true);
        $channel->method('isBlocked')->willReturn(true);
        $channel->method('getId')->willReturn(1);

        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channelRepo->method('findByChannelName')->willReturn($channel);

        $channelServiceActions = $this->createMock(ChannelServiceActionsPort::class);
        $channelServiceActions->expects(self::never())->method('kickFromChannel');

        $subscriber = $this->createSubscriber(
            $channelRepo,
            $this->createStub(ChannelLevelRepositoryInterface::class),
            $this->createStub(ChanUserAccountPort::class),
            $this->createStub(ChannelLookupPort::class),
            $this->createStub(NetworkUserLookupPort::class),
            $channelServiceActions,
            new ChanServAccessHelper(
                $this->createStub(ChannelAccessRepositoryInterface::class),
                $this->createStub(ChannelLevelRepositoryInterface::class),
            ),
        );

        $event = new UserJoinedChannelEvent('UID1', '#test');
        $subscriber->onUserJoined($event);
    }

    #[Test]
    public function onSyncCompleteSkipsSuspendedChannel(): void
    {
        $channel = $this->createStub(RegisteredChannel::class);
        $channel->method('isSuspended')->willReturn(true);
        $channel->method('isBlocked')->willReturn(true);
        $channel->method('getId')->willReturn(1);
        $channel->method('getName')->willReturn('#test');

        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channelRepo->method('listAll')->willReturn([$channel]);

        $channelServiceActions = $this->createMock(ChannelServiceActionsPort::class);
        $channelServiceActions->expects(self::never())->method('kickFromChannel');

        $subscriber = $this->createSubscriber(
            $channelRepo,
            $this->createStub(ChannelLevelRepositoryInterface::class),
            $this->createStub(ChanUserAccountPort::class),
            $this->createStub(ChannelLookupPort::class),
            $this->createStub(NetworkUserLookupPort::class),
            $channelServiceActions,
            new ChanServAccessHelper(
                $this->createStub(ChannelAccessRepositoryInterface::class),
                $this->createStub(ChannelLevelRepositoryInterface::class),
            ),
        );

        $event = new NetworkSynchronizationCompletedEvent('001');
        $subscriber->onSyncComplete($event);
    }

    #[Test]
    public function doesNotKickIdentifiedUserWithLevelZeroAndNojoinZero(): void
    {
        $registeredNick = new ChanAccountView(10, 'TestNick', 'en');

        $channel = $this->createStub(RegisteredChannel::class);
        $channel->method('getId')->willReturn(1);
        $channel->method('isFounder')->willReturn(false);

        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channelRepo->method('findByChannelName')->willReturn($channel);

        $nojoinLevel = new ChannelLevel(1, ChannelLevel::KEY_NOJOIN, 0);
        $levelRepo = $this->createStub(ChannelLevelRepositoryInterface::class);
        $levelRepo->method('findByChannelAndKey')->willReturn($nojoinLevel);

        $nickRepo = $this->createStub(ChanUserAccountPort::class);
        $nickRepo->method('findAccountByNick')->willReturn($registeredNick);

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

        $event = new UserJoinedChannelEvent('UID1', '#test');
        $subscriber->onUserJoined($event);
    }
}
