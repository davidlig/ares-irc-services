<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\ChanServ\Subscriber;

use App\Application\Port\ChannelLookupPort;
use App\Application\Port\ChannelServiceActionsPort;
use App\Application\Port\ChannelView;
use App\Application\Port\NetworkUserLookupPort;
use App\Application\Port\SenderView;
use App\Domain\ChanServ\Entity\ChannelAkick;
use App\Domain\ChanServ\Entity\RegisteredChannel;
use App\Domain\ChanServ\Repository\ChannelAkickRepositoryInterface;
use App\Domain\ChanServ\Repository\RegisteredChannelRepositoryInterface;
use App\Domain\IRC\Connection\ConnectionInterface;
use App\Domain\IRC\Event\NetworkSyncCompleteEvent;
use App\Domain\IRC\Event\UserJoinedChannelEvent;
use App\Domain\IRC\Network\ChannelMemberRole;
use App\Domain\IRC\ValueObject\ChannelName;
use App\Domain\IRC\ValueObject\Uid;
use App\Infrastructure\ChanServ\Subscriber\ChanServAkickEnforceSubscriber;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

#[CoversClass(ChanServAkickEnforceSubscriber::class)]
final class ChanServAkickEnforceSubscriberTest extends TestCase
{
    #[Test]
    public function kicksUserMatchingAkickMask(): void
    {
        $akick = ChannelAkick::create(1, 2, '*!*@*.isp.com', 'Spammer');

        $channel = $this->createStub(RegisteredChannel::class);
        $channel->method('getId')->willReturn(1);

        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channelRepo->method('findByChannelName')->willReturn($channel);

        $akickRepo = $this->createStub(ChannelAkickRepositoryInterface::class);
        $akickRepo->method('listByChannel')->willReturn([$akick]);

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
        $bans = [];
        $channelServiceActions = $this->createMock(ChannelServiceActionsPort::class);
        $channelServiceActions->expects(self::once())->method('setChannelModes')
            ->willReturnCallback(static function (string $channel, string $modes, array $params) use (&$bans): void {
                $bans[] = ['channel' => $channel, 'modes' => $modes, 'params' => $params];
            });
        $channelServiceActions->expects(self::once())->method('kickFromChannel')
            ->willReturnCallback(static function (string $channel, string $uid, string $reason) use (&$kicked): void {
                $kicked[] = ['channel' => $channel, 'uid' => $uid, 'reason' => $reason];
            });

        $subscriber = new ChanServAkickEnforceSubscriber(
            $channelRepo,
            $akickRepo,
            $this->createStub(ChannelLookupPort::class),
            $userLookup,
            $channelServiceActions,
            'ChanServ',
            new NullLogger(),
        );

        $event = new UserJoinedChannelEvent(new Uid('UID1'), new ChannelName('#test'), ChannelMemberRole::None);
        $subscriber->onUserJoined($event);

        self::assertCount(1, $bans);
        self::assertSame('#test', $bans[0]['channel']);
        self::assertSame('+b', $bans[0]['modes']);
        self::assertSame(['*!*@*.isp.com'], $bans[0]['params']);

        self::assertCount(1, $kicked);
        self::assertSame('#test', $kicked[0]['channel']);
        self::assertSame('UID1', $kicked[0]['uid']);
        self::assertSame('Spammer', $kicked[0]['reason']);
    }

    #[Test]
    public function doesNotKickUserNotMatchingAkick(): void
    {
        $akick = ChannelAkick::create(1, 2, '*!*@*.badsite.com', 'Spammer');

        $channel = $this->createStub(RegisteredChannel::class);
        $channel->method('getId')->willReturn(1);

        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channelRepo->method('findByChannelName')->willReturn($channel);

        $akickRepo = $this->createStub(ChannelAkickRepositoryInterface::class);
        $akickRepo->method('listByChannel')->willReturn([$akick]);

        $userView = new SenderView(
            'UID1',
            'User',
            'user',
            'user.good.com',
            'user.good.com',
            '192.168.1.1',
            false,
            false,
            '001',
            'user.good.com',
        );

        $userLookup = $this->createStub(NetworkUserLookupPort::class);
        $userLookup->method('findByUid')->willReturn($userView);

        $channelServiceActions = $this->createMock(ChannelServiceActionsPort::class);
        $channelServiceActions->expects(self::never())->method('setChannelModes');
        $channelServiceActions->expects(self::never())->method('kickFromChannel');

        $subscriber = new ChanServAkickEnforceSubscriber(
            $channelRepo,
            $akickRepo,
            $this->createStub(ChannelLookupPort::class),
            $userLookup,
            $channelServiceActions,
            'ChanServ',
            new NullLogger(),
        );

        $event = new UserJoinedChannelEvent(new Uid('UID1'), new ChannelName('#test'), ChannelMemberRole::None);
        $subscriber->onUserJoined($event);
    }

    #[Test]
    public function doesNotKickOperUser(): void
    {
        $akick = ChannelAkick::create(1, 2, '*!*@*');

        $channel = $this->createStub(RegisteredChannel::class);
        $channel->method('getId')->willReturn(1);

        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channelRepo->method('findByChannelName')->willReturn($channel);

        $akickRepo = $this->createStub(ChannelAkickRepositoryInterface::class);
        $akickRepo->method('listByChannel')->willReturn([$akick]);

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

        $channelServiceActions = $this->createMock(ChannelServiceActionsPort::class);
        $channelServiceActions->expects(self::never())->method('setChannelModes');
        $channelServiceActions->expects(self::never())->method('kickFromChannel');

        $subscriber = new ChanServAkickEnforceSubscriber(
            $channelRepo,
            $akickRepo,
            $this->createStub(ChannelLookupPort::class),
            $userLookup,
            $channelServiceActions,
            'ChanServ',
            new NullLogger(),
        );

        $event = new UserJoinedChannelEvent(new Uid('UID1'), new ChannelName('#test'), ChannelMemberRole::None);
        $subscriber->onUserJoined($event);
    }

    #[Test]
    public function doesNothingWhenChannelNotRegistered(): void
    {
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channelRepo->method('findByChannelName')->willReturn(null);

        $channelServiceActions = $this->createMock(ChannelServiceActionsPort::class);
        $channelServiceActions->expects(self::never())->method('setChannelModes');
        $channelServiceActions->expects(self::never())->method('kickFromChannel');

        $subscriber = new ChanServAkickEnforceSubscriber(
            $channelRepo,
            $this->createStub(ChannelAkickRepositoryInterface::class),
            $this->createStub(ChannelLookupPort::class),
            $this->createStub(NetworkUserLookupPort::class),
            $channelServiceActions,
            'ChanServ',
            new NullLogger(),
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

        $channelServiceActions = $this->createMock(ChannelServiceActionsPort::class);
        $channelServiceActions->expects(self::never())->method('setChannelModes');
        $channelServiceActions->expects(self::never())->method('kickFromChannel');

        $subscriber = new ChanServAkickEnforceSubscriber(
            $channelRepo,
            $this->createStub(ChannelAkickRepositoryInterface::class),
            $this->createStub(ChannelLookupPort::class),
            $userLookup,
            $channelServiceActions,
            'ChanServ',
            new NullLogger(),
        );

        $event = new UserJoinedChannelEvent(new Uid('UID1'), new ChannelName('#test'), ChannelMemberRole::None);
        $subscriber->onUserJoined($event);
    }

    #[Test]
    public function skipsExpiredAkick(): void
    {
        $expiredAkick = ChannelAkick::create(1, 2, '*!*@*', 'Expired', new DateTimeImmutable('-1 day'));

        $channel = $this->createStub(RegisteredChannel::class);
        $channel->method('getId')->willReturn(1);

        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channelRepo->method('findByChannelName')->willReturn($channel);

        $akickRepo = $this->createStub(ChannelAkickRepositoryInterface::class);
        $akickRepo->method('listByChannel')->willReturn([$expiredAkick]);

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
        $channelServiceActions->expects(self::never())->method('setChannelModes');
        $channelServiceActions->expects(self::never())->method('kickFromChannel');

        $subscriber = new ChanServAkickEnforceSubscriber(
            $channelRepo,
            $akickRepo,
            $this->createStub(ChannelLookupPort::class),
            $userLookup,
            $channelServiceActions,
            'ChanServ',
            new NullLogger(),
        );

        $event = new UserJoinedChannelEvent(new Uid('UID1'), new ChannelName('#test'), ChannelMemberRole::None);
        $subscriber->onUserJoined($event);
    }

    #[Test]
    public function usesDefaultReasonWhenAkickHasNone(): void
    {
        $akick = ChannelAkick::create(1, 2, '*!*@*.isp.com');

        $channel = $this->createStub(RegisteredChannel::class);
        $channel->method('getId')->willReturn(1);

        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channelRepo->method('findByChannelName')->willReturn($channel);

        $akickRepo = $this->createStub(ChannelAkickRepositoryInterface::class);
        $akickRepo->method('listByChannel')->willReturn([$akick]);

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
        $channelServiceActions = $this->createStub(ChannelServiceActionsPort::class);
        $channelServiceActions->method('setChannelModes');
        $channelServiceActions->method('kickFromChannel')
            ->willReturnCallback(static function (string $channel, string $uid, string $reason) use (&$kicked): void {
                $kicked[] = ['channel' => $channel, 'uid' => $uid, 'reason' => $reason];
            });

        $subscriber = new ChanServAkickEnforceSubscriber(
            $channelRepo,
            $akickRepo,
            $this->createStub(ChannelLookupPort::class),
            $userLookup,
            $channelServiceActions,
            'ChanServ',
            new NullLogger(),
        );

        $event = new UserJoinedChannelEvent(new Uid('UID1'), new ChannelName('#test'), ChannelMemberRole::None);
        $subscriber->onUserJoined($event);

        self::assertCount(1, $kicked);
        self::assertSame('AKICK: *!*@*.isp.com', $kicked[0]['reason']);
    }

    #[Test]
    public function stopsAtFirstMatch(): void
    {
        $akick1 = ChannelAkick::create(1, 2, '*!*@*.isp.com');
        $akick2 = ChannelAkick::create(1, 2, '*!*@*');

        $channel = $this->createStub(RegisteredChannel::class);
        $channel->method('getId')->willReturn(1);

        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channelRepo->method('findByChannelName')->willReturn($channel);

        $akickRepo = $this->createStub(ChannelAkickRepositoryInterface::class);
        $akickRepo->method('listByChannel')->willReturn([$akick1, $akick2]);

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

        $kickCount = 0;
        $channelServiceActions = $this->createStub(ChannelServiceActionsPort::class);
        $channelServiceActions->method('setChannelModes');
        $channelServiceActions->method('kickFromChannel')
            ->willReturnCallback(static function () use (&$kickCount): void {
                ++$kickCount;
            });

        $subscriber = new ChanServAkickEnforceSubscriber(
            $channelRepo,
            $akickRepo,
            $this->createStub(ChannelLookupPort::class),
            $userLookup,
            $channelServiceActions,
            'ChanServ',
            new NullLogger(),
        );

        $event = new UserJoinedChannelEvent(new Uid('UID1'), new ChannelName('#test'), ChannelMemberRole::None);
        $subscriber->onUserJoined($event);

        self::assertSame(1, $kickCount);
    }

    #[Test]
    public function getSubscribedEventsReturnsCorrectEvents(): void
    {
        $expected = [
            UserJoinedChannelEvent::class => ['onUserJoined', 0],
            NetworkSyncCompleteEvent::class => ['onSyncComplete', 0],
        ];
        self::assertSame($expected, ChanServAkickEnforceSubscriber::getSubscribedEvents());
    }

    #[Test]
    public function onSyncCompleteEnforcesAkicksOnAllChannels(): void
    {
        $akick = ChannelAkick::create(1, 2, '*!*@*.isp.com', 'Spammer');

        $channel = $this->createStub(RegisteredChannel::class);
        $channel->method('getId')->willReturn(1);
        $channel->method('getName')->willReturn('#test');

        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channelRepo->method('listAll')->willReturn([$channel]);

        $akickRepo = $this->createStub(ChannelAkickRepositoryInterface::class);
        $akickRepo->method('listByChannel')->willReturn([$akick]);

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
        $bans = [];
        $channelServiceActions = $this->createStub(ChannelServiceActionsPort::class);
        $channelServiceActions->method('setChannelModes')
            ->willReturnCallback(static function (string $channel, string $modes, array $params) use (&$bans): void {
                $bans[] = ['channel' => $channel, 'modes' => $modes, 'params' => $params];
            });
        $channelServiceActions->method('kickFromChannel')
            ->willReturnCallback(static function (string $channel, string $uid, string $reason) use (&$kicked): void {
                $kicked[] = ['channel' => $channel, 'uid' => $uid, 'reason' => $reason];
            });

        $subscriber = new ChanServAkickEnforceSubscriber(
            $channelRepo,
            $akickRepo,
            $channelLookup,
            $userLookup,
            $channelServiceActions,
            'ChanServ',
            new NullLogger(),
        );

        $connection = $this->createStub(ConnectionInterface::class);
        $event = new NetworkSyncCompleteEvent($connection, '001');
        $subscriber->onSyncComplete($event);

        self::assertCount(1, $bans);
        self::assertSame('#test', $bans[0]['channel']);
        self::assertSame('+b', $bans[0]['modes']);
        self::assertSame(['*!*@*.isp.com'], $bans[0]['params']);

        self::assertCount(1, $kicked);
        self::assertSame('#test', $kicked[0]['channel']);
        self::assertSame('UID1', $kicked[0]['uid']);
        self::assertSame('Spammer', $kicked[0]['reason']);
    }

    #[Test]
    public function onSyncCompleteSkipsExpiredAkicks(): void
    {
        $expiredAkick = ChannelAkick::create(1, 2, '*!*@*', 'Expired', new DateTimeImmutable('-1 day'));

        $channel = $this->createStub(RegisteredChannel::class);
        $channel->method('getId')->willReturn(1);
        $channel->method('getName')->willReturn('#test');

        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channelRepo->method('listAll')->willReturn([$channel]);

        $akickRepo = $this->createStub(ChannelAkickRepositoryInterface::class);
        $akickRepo->method('listByChannel')->willReturn([$expiredAkick]);

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

        $channelServiceActions = $this->createMock(ChannelServiceActionsPort::class);
        $channelServiceActions->expects(self::never())->method('setChannelModes');
        $channelServiceActions->expects(self::never())->method('kickFromChannel');

        $subscriber = new ChanServAkickEnforceSubscriber(
            $channelRepo,
            $akickRepo,
            $channelLookup,
            $userLookup,
            $channelServiceActions,
            'ChanServ',
            new NullLogger(),
        );

        $connection = $this->createStub(ConnectionInterface::class);
        $event = new NetworkSyncCompleteEvent($connection, '001');
        $subscriber->onSyncComplete($event);
    }

    #[Test]
    public function onSyncCompleteSkipsOperUsers(): void
    {
        $akick = ChannelAkick::create(1, 2, '*!*@*', 'All banned');

        $channel = $this->createStub(RegisteredChannel::class);
        $channel->method('getId')->willReturn(1);
        $channel->method('getName')->willReturn('#test');

        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channelRepo->method('listAll')->willReturn([$channel]);

        $akickRepo = $this->createStub(ChannelAkickRepositoryInterface::class);
        $akickRepo->method('listByChannel')->willReturn([$akick]);

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
        $channelServiceActions->expects(self::never())->method('setChannelModes');
        $channelServiceActions->expects(self::never())->method('kickFromChannel');

        $subscriber = new ChanServAkickEnforceSubscriber(
            $channelRepo,
            $akickRepo,
            $channelLookup,
            $userLookup,
            $channelServiceActions,
            'ChanServ',
            new NullLogger(),
        );

        $connection = $this->createStub(ConnectionInterface::class);
        $event = new NetworkSyncCompleteEvent($connection, '001');
        $subscriber->onSyncComplete($event);
    }

    #[Test]
    public function onSyncCompleteHandlesMultipleChannels(): void
    {
        $akick1 = ChannelAkick::create(1, 2, '*!*@*.isp.com', 'Spam');
        $akick2 = ChannelAkick::create(2, 2, '*!*@*.badsite.com', 'Bad');

        $channel1 = $this->createStub(RegisteredChannel::class);
        $channel1->method('getId')->willReturn(1);
        $channel1->method('getName')->willReturn('#test1');

        $channel2 = $this->createStub(RegisteredChannel::class);
        $channel2->method('getId')->willReturn(2);
        $channel2->method('getName')->willReturn('#test2');

        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channelRepo->method('listAll')->willReturn([$channel1, $channel2]);

        $akickRepo = $this->createStub(ChannelAkickRepositoryInterface::class);
        $akickRepo->method('listByChannel')->willReturnCallback(static fn (int $id): array => 1 === $id ? [$akick1] : [$akick2]);

        $userView1 = new SenderView('UID1', 'User1', 'user', 'user.isp.com', 'user.isp.com', '192.168.1.1', false, false, '001', 'user.isp.com');
        $userView2 = new SenderView('UID2', 'User2', 'user', 'user.badsite.com', 'user.badsite.com', '192.168.1.2', false, false, '001', 'user.badsite.com');

        $userLookup = $this->createStub(NetworkUserLookupPort::class);
        $userLookup->method('findByUid')->willReturnMap([
            ['UID1', $userView1],
            ['UID2', $userView2],
        ]);

        $channelView1 = new ChannelView('#test1', '+nt', null, 1, [['uid' => 'UID1', 'roleLetter' => '']]);
        $channelView2 = new ChannelView('#test2', '+nt', null, 1, [['uid' => 'UID2', 'roleLetter' => '']]);

        $channelLookup = $this->createStub(ChannelLookupPort::class);
        $channelLookup->method('findByChannelName')->willReturnCallback(static fn (string $name): ?ChannelView => '#test1' === $name ? $channelView1 : $channelView2);

        $kickCount = 0;
        $channelServiceActions = $this->createStub(ChannelServiceActionsPort::class);
        $channelServiceActions->method('setChannelModes');
        $channelServiceActions->method('kickFromChannel')
            ->willReturnCallback(static function () use (&$kickCount): void {
                ++$kickCount;
            });

        $subscriber = new ChanServAkickEnforceSubscriber(
            $channelRepo,
            $akickRepo,
            $channelLookup,
            $userLookup,
            $channelServiceActions,
            'ChanServ',
            new NullLogger(),
        );

        $connection = $this->createStub(ConnectionInterface::class);
        $event = new NetworkSyncCompleteEvent($connection, '001');
        $subscriber->onSyncComplete($event);

        self::assertSame(2, $kickCount);
    }

    #[Test]
    public function onSyncCompleteSkipsChannelWhenChannelViewNotFound(): void
    {
        $channel = $this->createStub(RegisteredChannel::class);
        $channel->method('getId')->willReturn(1);
        $channel->method('getName')->willReturn('#test');

        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channelRepo->method('listAll')->willReturn([$channel]);

        $channelLookup = $this->createStub(ChannelLookupPort::class);
        $channelLookup->method('findByChannelName')->willReturn(null);

        $channelServiceActions = $this->createMock(ChannelServiceActionsPort::class);
        $channelServiceActions->expects(self::never())->method('setChannelModes');
        $channelServiceActions->expects(self::never())->method('kickFromChannel');

        $subscriber = new ChanServAkickEnforceSubscriber(
            $channelRepo,
            $this->createStub(ChannelAkickRepositoryInterface::class),
            $channelLookup,
            $this->createStub(NetworkUserLookupPort::class),
            $channelServiceActions,
            'ChanServ',
            new NullLogger(),
        );

        $connection = $this->createStub(ConnectionInterface::class);
        $event = new NetworkSyncCompleteEvent($connection, '001');
        $subscriber->onSyncComplete($event);
    }

    #[Test]
    public function onSyncCompleteDoesNothingWhenNoAkicksExist(): void
    {
        $channel = $this->createStub(RegisteredChannel::class);
        $channel->method('getId')->willReturn(1);
        $channel->method('getName')->willReturn('#test');

        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channelRepo->method('listAll')->willReturn([$channel]);

        $akickRepo = $this->createStub(ChannelAkickRepositoryInterface::class);
        $akickRepo->method('listByChannel')->willReturn([]);

        $channelView = new ChannelView('#test', '+nt', null, 1, [['uid' => 'UID1', 'roleLetter' => '']]);
        $channelLookup = $this->createStub(ChannelLookupPort::class);
        $channelLookup->method('findByChannelName')->willReturn($channelView);

        $channelServiceActions = $this->createMock(ChannelServiceActionsPort::class);
        $channelServiceActions->expects(self::never())->method('setChannelModes');
        $channelServiceActions->expects(self::never())->method('kickFromChannel');

        $subscriber = new ChanServAkickEnforceSubscriber(
            $channelRepo,
            $akickRepo,
            $channelLookup,
            $this->createStub(NetworkUserLookupPort::class),
            $channelServiceActions,
            'ChanServ',
            new NullLogger(),
        );

        $connection = $this->createStub(ConnectionInterface::class);
        $event = new NetworkSyncCompleteEvent($connection, '001');
        $subscriber->onSyncComplete($event);
    }

    #[Test]
    public function onSyncCompleteDoesNothingWhenAllAkicksExpired(): void
    {
        $expiredAkick1 = ChannelAkick::create(1, 2, '*!*@*.isp.com', 'Spam', new DateTimeImmutable('-1 day'));
        $expiredAkick2 = ChannelAkick::create(1, 3, '*!*@*.badsite.com', 'Bad', new DateTimeImmutable('-2 hours'));

        $channel = $this->createStub(RegisteredChannel::class);
        $channel->method('getId')->willReturn(1);
        $channel->method('getName')->willReturn('#test');

        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channelRepo->method('listAll')->willReturn([$channel]);

        $akickRepo = $this->createStub(ChannelAkickRepositoryInterface::class);
        $akickRepo->method('listByChannel')->willReturn([$expiredAkick1, $expiredAkick2]);

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

        $channelServiceActions = $this->createMock(ChannelServiceActionsPort::class);
        $channelServiceActions->expects(self::never())->method('setChannelModes');
        $channelServiceActions->expects(self::never())->method('kickFromChannel');

        $subscriber = new ChanServAkickEnforceSubscriber(
            $channelRepo,
            $akickRepo,
            $channelLookup,
            $userLookup,
            $channelServiceActions,
            'ChanServ',
            new NullLogger(),
        );

        $connection = $this->createStub(ConnectionInterface::class);
        $event = new NetworkSyncCompleteEvent($connection, '001');
        $subscriber->onSyncComplete($event);
    }
}
