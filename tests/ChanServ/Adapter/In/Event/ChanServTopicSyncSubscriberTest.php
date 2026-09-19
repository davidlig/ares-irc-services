<?php

declare(strict_types=1);

namespace App\Tests\ChanServ\Adapter\In\Event;

use App\ChanServ\Adapter\In\Event\ChanServTopicSyncSubscriber;
use App\ChanServ\Adapter\Out\Network\IrcChannelTopicActions;
use App\ChanServ\Adapter\Out\Network\IrcChannelTopicNetworkQuery;
use App\ChanServ\Adapter\Out\Time\SystemChannelTopicClock;
use App\ChanServ\Application\Port\Out\RegisteredChannelRepositoryInterface;
use App\ChanServ\Application\UseCase\SynchronizeTopic\SynchronizeReceivedChannelTopic;
use App\ChanServ\Application\UseCase\SynchronizeTopic\SynchronizeReceivedChannelTopicHandler;
use App\ChanServ\Domain\Entity\RegisteredChannel;
use App\Irc\Application\Port\In\ChannelLookupPort;
use App\Irc\Application\Port\In\ChannelServiceActionsPort;
use App\Irc\Application\Port\In\ChannelSyncCompletedRegistryInterface;
use App\Irc\Application\Port\In\UidResolverInterface;
use App\Irc\Application\PublishedEvent\ChannelTopicReceivedEvent;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[CoversClass(ChanServTopicSyncSubscriber::class)]
#[CoversClass(IrcChannelTopicActions::class)]
#[CoversClass(IrcChannelTopicNetworkQuery::class)]
#[CoversClass(SystemChannelTopicClock::class)]
#[CoversClass(SynchronizeReceivedChannelTopic::class)]
#[CoversClass(SynchronizeReceivedChannelTopicHandler::class)]
final class ChanServTopicSyncSubscriberTest extends TestCase
{
    private MockObject&RegisteredChannelRepositoryInterface $channelRepository;

    private ChannelServiceActionsPort&MockObject $channelServiceActions;

    private ChannelSyncCompletedRegistryInterface&MockObject $syncCompletedRegistry;

    private UidResolverInterface $uidResolver;

    private ChanServTopicSyncSubscriber $subscriber;

    protected function setUp(): void
    {
        $this->channelRepository = $this->createMock(RegisteredChannelRepositoryInterface::class);
        $this->channelServiceActions = $this->createMock(ChannelServiceActionsPort::class);
        $this->syncCompletedRegistry = $this->createMock(ChannelSyncCompletedRegistryInterface::class);
        $this->uidResolver = $this->createStub(UidResolverInterface::class);
        $this->subscriber = $this->createSubscriber($this->uidResolver);
    }

    private function createSubscriber(UidResolverInterface $uidResolver): ChanServTopicSyncSubscriber
    {
        return new ChanServTopicSyncSubscriber(new SynchronizeReceivedChannelTopicHandler(
            $this->channelRepository,
            new IrcChannelTopicNetworkQuery(
                $this->createStub(ChannelLookupPort::class),
                $this->syncCompletedRegistry,
                $uidResolver,
            ),
            new IrcChannelTopicActions($this->channelServiceActions),
            new SystemChannelTopicClock(),
            'ChanServ',
            'NickServ',
        ));
    }

    #[Test]
    public function subscribesToChannelTopicReceivedEvent(): void
    {
        $this->channelRepository->expects(self::never())->method('findByChannelName');
        $this->channelServiceActions->expects(self::never())->method('setChannelTopic');
        $this->syncCompletedRegistry->expects(self::never())->method('isSyncCompleted');

        self::assertSame(
            [ChannelTopicReceivedEvent::class => ['onTopicReceived', 0]],
            ChanServTopicSyncSubscriber::getSubscribedEvents(),
        );
    }

    #[Test]
    public function reAppliesStoredTopicWhenTopicLockEnabled(): void
    {
        $registered = $this->createMock(RegisteredChannel::class);
        $registered->expects(self::atLeastOnce())->method('isTopicLock')->willReturn(true);
        $registered->expects(self::atLeastOnce())->method('getTopic')->willReturn('Locked topic from DB');

        $this->channelRepository
            ->expects(self::once())
            ->method('findByChannelName')
            ->with('#test')
            ->willReturn($registered);

        $this->channelServiceActions
            ->expects(self::once())
            ->method('setChannelTopic')
            ->with('#test', 'Locked topic from DB');

        $this->syncCompletedRegistry
            ->expects(self::never())
            ->method('isSyncCompleted');

        $event = new ChannelTopicReceivedEvent(
            channelName: '#test',
            topic: 'New topic from user',
            setterNick: 'SomeUser',
        );

        $this->subscriber->onTopicReceived($event);
    }

    #[Test]
    public function doesNothingWhenChannelNotRegistered(): void
    {
        $this->channelRepository
            ->expects(self::once())
            ->method('findByChannelName')
            ->with('#test')
            ->willReturn(null);

        $this->channelServiceActions
            ->expects(self::never())
            ->method('setChannelTopic');
        $this->syncCompletedRegistry->expects(self::never())->method('isSyncCompleted');

        $event = new ChannelTopicReceivedEvent(
            channelName: '#test',
            topic: 'New topic',
        );

        $this->subscriber->onTopicReceived($event);
    }

    #[Test]
    public function clearsTopicWhenTopicLockEnabledButNoStoredTopic(): void
    {
        $registered = $this->createMock(RegisteredChannel::class);
        $registered->expects(self::atLeastOnce())->method('isTopicLock')->willReturn(true);
        $registered->expects(self::atLeastOnce())->method('getTopic')->willReturn(null);

        $this->channelRepository
            ->expects(self::once())
            ->method('findByChannelName')
            ->with('#test')
            ->willReturn($registered);

        $this->channelServiceActions
            ->expects(self::once())
            ->method('setChannelTopic')
            ->with('#test', null);
        $this->syncCompletedRegistry->expects(self::never())->method('isSyncCompleted');

        $event = new ChannelTopicReceivedEvent(
            channelName: '#test',
            topic: 'New topic',
        );

        $this->subscriber->onTopicReceived($event);
    }

    #[Test]
    public function doesNotPersistWhenChannelSyncNotCompleted(): void
    {
        $registered = $this->createMock(RegisteredChannel::class);
        $registered->expects(self::atLeastOnce())->method('isTopicLock')->willReturn(false);

        $this->channelRepository
            ->expects(self::once())
            ->method('findByChannelName')
            ->with('#test')
            ->willReturn($registered);

        $this->syncCompletedRegistry
            ->expects(self::once())
            ->method('isSyncCompleted')
            ->with('#test')
            ->willReturn(false);

        $this->syncCompletedRegistry
            ->expects(self::never())
            ->method('getSyncCompletedAt');
        $this->channelRepository
            ->expects(self::never())
            ->method('save');
        $this->channelServiceActions->expects(self::never())->method('setChannelTopic');

        $event = new ChannelTopicReceivedEvent(
            channelName: '#test',
            topic: 'New topic',
            setterNick: 'User',
        );

        $this->subscriber->onTopicReceived($event);
    }

    #[Test]
    public function doesNotPersistWithinGracePeriod(): void
    {
        $registered = $this->createMock(RegisteredChannel::class);
        $registered->expects(self::atLeastOnce())->method('isTopicLock')->willReturn(false);

        $this->channelRepository
            ->expects(self::once())
            ->method('findByChannelName')
            ->with('#test')
            ->willReturn($registered);

        $this->syncCompletedRegistry
            ->expects(self::once())
            ->method('isSyncCompleted')
            ->with('#test')
            ->willReturn(true);

        $this->syncCompletedRegistry
            ->expects(self::once())
            ->method('getSyncCompletedAt')
            ->with('#test')
            ->willReturn(microtime(true) - 0.5);

        $this->channelRepository
            ->expects(self::never())
            ->method('save');
        $this->channelServiceActions->expects(self::never())->method('setChannelTopic');

        $event = new ChannelTopicReceivedEvent(
            channelName: '#test',
            topic: 'New topic',
        );

        $this->subscriber->onTopicReceived($event);
    }

    #[Test]
    public function ignoresSetterNickWhenServicesUser(): void
    {
        $registered = $this->createMock(RegisteredChannel::class);
        $registered->expects(self::atLeastOnce())->method('isTopicLock')->willReturn(false);
        $registered->expects(self::once())->method('updateTopic')->with('New topic', self::isInstanceOf(DateTimeImmutable::class), null);

        $this->channelRepository
            ->expects(self::once())
            ->method('findByChannelName')
            ->with('#test')
            ->willReturn($registered);

        $this->syncCompletedRegistry
            ->expects(self::atLeastOnce())
            ->method('isSyncCompleted')->willReturn(true);
        $this->syncCompletedRegistry
            ->expects(self::atLeastOnce())
            ->method('getSyncCompletedAt')->willReturn(microtime(true) - 5);

        $this->channelRepository
            ->expects(self::once())
            ->method('save');
        $this->channelServiceActions->expects(self::never())->method('setChannelTopic');

        $event = new ChannelTopicReceivedEvent(
            channelName: '#test',
            topic: 'New topic',
            setterNick: 'ChanServ',
        );

        $this->subscriber->onTopicReceived($event);
    }

    #[Test]
    public function reAppliesStoredTopicEvenWhenSameAsNew(): void
    {
        $registered = $this->createMock(RegisteredChannel::class);
        $registered->expects(self::atLeastOnce())->method('isTopicLock')->willReturn(true);
        $registered->expects(self::atLeastOnce())->method('getTopic')->willReturn('Locked topic');

        $this->channelRepository
            ->expects(self::once())
            ->method('findByChannelName')
            ->with('#test')
            ->willReturn($registered);

        $this->channelServiceActions
            ->expects(self::once())
            ->method('setChannelTopic')
            ->with('#test', 'Locked topic');

        $this->syncCompletedRegistry
            ->expects(self::never())
            ->method('isSyncCompleted');

        $event = new ChannelTopicReceivedEvent(
            channelName: '#test',
            topic: 'Locked topic',
            setterNick: 'SomeUser',
        );

        $this->subscriber->onTopicReceived($event);
    }

    #[Test]
    public function ignoresSetterNickCaseInsensitive(): void
    {
        $registered = $this->createMock(RegisteredChannel::class);
        $registered->expects(self::atLeastOnce())->method('isTopicLock')->willReturn(false);
        $registered->expects(self::once())->method('updateTopic')->with('New topic', self::isInstanceOf(DateTimeImmutable::class), null);

        $this->channelRepository
            ->expects(self::once())
            ->method('findByChannelName')
            ->with('#test')
            ->willReturn($registered);

        $this->syncCompletedRegistry
            ->expects(self::atLeastOnce())
            ->method('isSyncCompleted')->willReturn(true);
        $this->syncCompletedRegistry
            ->expects(self::atLeastOnce())
            ->method('getSyncCompletedAt')->willReturn(microtime(true) - 5);

        $this->channelRepository
            ->expects(self::once())
            ->method('save');
        $this->channelServiceActions->expects(self::never())->method('setChannelTopic');

        $event = new ChannelTopicReceivedEvent(
            channelName: '#test',
            topic: 'New topic',
            setterNick: 'CHANSERV',
        );

        $this->subscriber->onTopicReceived($event);
    }

    #[Test]
    public function ignoresNickServSetterNick(): void
    {
        $registered = $this->createMock(RegisteredChannel::class);
        $registered->expects(self::atLeastOnce())->method('isTopicLock')->willReturn(false);
        $registered->expects(self::once())->method('updateTopic')->with('New topic', self::isInstanceOf(DateTimeImmutable::class), null);

        $this->channelRepository
            ->expects(self::once())
            ->method('findByChannelName')
            ->with('#test')
            ->willReturn($registered);

        $this->syncCompletedRegistry
            ->expects(self::atLeastOnce())
            ->method('isSyncCompleted')->willReturn(true);
        $this->syncCompletedRegistry
            ->expects(self::atLeastOnce())
            ->method('getSyncCompletedAt')->willReturn(microtime(true) - 5);

        $this->channelRepository
            ->expects(self::once())
            ->method('save');
        $this->channelServiceActions->expects(self::never())->method('setChannelTopic');

        $event = new ChannelTopicReceivedEvent(
            channelName: '#test',
            topic: 'New topic',
            setterNick: 'NickServ',
        );

        $this->subscriber->onTopicReceived($event);
    }

    #[Test]
    public function ignoresServerHostnameAsSetterNick(): void
    {
        $registered = $this->createMock(RegisteredChannel::class);
        $registered->expects(self::atLeastOnce())->method('isTopicLock')->willReturn(false);
        $registered->expects(self::once())->method('updateTopic')->with('New topic', self::isInstanceOf(DateTimeImmutable::class), null);

        $this->channelRepository
            ->expects(self::once())
            ->method('findByChannelName')
            ->with('#test')
            ->willReturn($registered);

        $this->syncCompletedRegistry
            ->expects(self::atLeastOnce())
            ->method('isSyncCompleted')->willReturn(true);
        $this->syncCompletedRegistry
            ->expects(self::atLeastOnce())
            ->method('getSyncCompletedAt')->willReturn(microtime(true) - 5);

        $this->channelRepository
            ->expects(self::once())
            ->method('save');
        $this->channelServiceActions->expects(self::never())->method('setChannelTopic');

        $event = new ChannelTopicReceivedEvent(
            channelName: '#test',
            topic: 'New topic',
            setterNick: 'ares-services.davidlig.net',
        );

        $this->subscriber->onTopicReceived($event);
    }

    #[Test]
    public function persistsTopicWithNullSetterNick(): void
    {
        $registered = $this->createMock(RegisteredChannel::class);
        $registered->expects(self::atLeastOnce())->method('isTopicLock')->willReturn(false);
        $registered->expects(self::once())->method('updateTopic')->with('New topic', self::isInstanceOf(DateTimeImmutable::class), null);

        $this->channelRepository
            ->expects(self::once())
            ->method('findByChannelName')
            ->with('#test')
            ->willReturn($registered);

        $this->syncCompletedRegistry
            ->expects(self::once())
            ->method('isSyncCompleted')
            ->with('#test')
            ->willReturn(true);

        $this->syncCompletedRegistry
            ->expects(self::once())
            ->method('getSyncCompletedAt')
            ->with('#test')
            ->willReturn(microtime(true) - 5);

        $this->channelRepository
            ->expects(self::once())
            ->method('save')
            ->with($registered);
        $this->channelServiceActions->expects(self::never())->method('setChannelTopic');

        $event = new ChannelTopicReceivedEvent(
            channelName: '#test',
            topic: 'New topic',
            setterNick: null,
        );

        $this->subscriber->onTopicReceived($event);
    }

    #[Test]
    public function persistsTopicWithUserSetterNick(): void
    {
        $registered = $this->createMock(RegisteredChannel::class);
        $registered->expects(self::atLeastOnce())->method('isTopicLock')->willReturn(false);
        $registered->expects(self::once())->method('updateTopic')->with('New topic', self::isInstanceOf(DateTimeImmutable::class), 'TestUser');

        $this->channelRepository
            ->expects(self::once())
            ->method('findByChannelName')
            ->with('#test')
            ->willReturn($registered);

        $this->syncCompletedRegistry
            ->expects(self::once())
            ->method('isSyncCompleted')
            ->with('#test')
            ->willReturn(true);

        $this->syncCompletedRegistry
            ->expects(self::once())
            ->method('getSyncCompletedAt')
            ->with('#test')
            ->willReturn(microtime(true) - 5);

        $this->channelRepository
            ->expects(self::once())
            ->method('save')
            ->with($registered);
        $this->channelServiceActions->expects(self::never())->method('setChannelTopic');

        $event = new ChannelTopicReceivedEvent(
            channelName: '#test',
            topic: 'New topic',
            setterNick: 'TestUser',
        );

        $this->subscriber->onTopicReceived($event);
    }

    #[Test]
    public function onTopicReceivedGracePeriodBoundaryJustUnderTwoSeconds(): void
    {
        $registered = $this->createMock(RegisteredChannel::class);
        $registered->expects(self::atLeastOnce())->method('isTopicLock')->willReturn(false);

        $this->channelRepository
            ->expects(self::once())
            ->method('findByChannelName')
            ->with('#test')
            ->willReturn($registered);

        $this->syncCompletedRegistry
            ->expects(self::once())
            ->method('isSyncCompleted')
            ->with('#test')
            ->willReturn(true);

        $this->syncCompletedRegistry
            ->expects(self::once())
            ->method('getSyncCompletedAt')
            ->with('#test')
            ->willReturn(microtime(true) - 1.9);

        $this->channelRepository
            ->expects(self::never())
            ->method('save');
        $this->channelServiceActions->expects(self::never())->method('setChannelTopic');

        $event = new ChannelTopicReceivedEvent(
            channelName: '#test',
            topic: 'New topic',
        );

        $this->subscriber->onTopicReceived($event);
    }

    #[Test]
    public function onTopicReceivedSkipsSuspendedChannel(): void
    {
        $registered = $this->createStub(RegisteredChannel::class);
        $registered->method('isSuspended')->willReturn(true);
        $registered->method('isBlocked')->willReturn(true);

        $this->channelRepository
            ->expects(self::once())
            ->method('findByChannelName')
            ->with('#test')
            ->willReturn($registered);
        $this->channelServiceActions
            ->expects(self::never())
            ->method('setChannelTopic');
        $this->syncCompletedRegistry->expects(self::never())->method('isSyncCompleted');

        $event = new ChannelTopicReceivedEvent(
            channelName: '#test',
            topic: 'New topic',
            setterNick: 'User',
        );
        $this->subscriber->onTopicReceived($event);
    }

    #[Test]
    public function resolvesSourceUidToSetterNick(): void
    {
        $uidResolver = $this->createMock(UidResolverInterface::class);
        $uidResolver->expects(self::once())->method('resolveUidToNick')->with('994AAAGUW')->willReturn('davidlig');

        $subscriber = $this->createSubscriber($uidResolver);

        $registered = $this->createMock(RegisteredChannel::class);
        $registered->expects(self::atLeastOnce())->method('isTopicLock')->willReturn(false);
        $registered->expects(self::once())->method('updateTopic')->with('New topic', self::isInstanceOf(DateTimeImmutable::class), 'davidlig');

        $this->channelRepository
            ->expects(self::once())
            ->method('findByChannelName')
            ->with('#test')
            ->willReturn($registered);

        $this->syncCompletedRegistry
            ->expects(self::once())
            ->method('isSyncCompleted')
            ->with('#test')
            ->willReturn(true);

        $this->syncCompletedRegistry
            ->expects(self::once())
            ->method('getSyncCompletedAt')
            ->with('#test')
            ->willReturn(microtime(true) - 5);

        $this->channelRepository
            ->expects(self::once())
            ->method('save')
            ->with($registered);
        $this->channelServiceActions->expects(self::never())->method('setChannelTopic');

        $event = new ChannelTopicReceivedEvent(
            channelName: '#test',
            topic: 'New topic',
            setterNick: null,
            sourceUid: '994AAAGUW',
        );

        $subscriber->onTopicReceived($event);
    }

    #[Test]
    public function sourceUidUnresolvedYieldsNullSetterNick(): void
    {
        $uidResolver = $this->createMock(UidResolverInterface::class);
        $uidResolver->expects(self::once())->method('resolveUidToNick')->with('994ZZZZZZ')->willReturn(null);

        $subscriber = $this->createSubscriber($uidResolver);

        $registered = $this->createMock(RegisteredChannel::class);
        $registered->expects(self::atLeastOnce())->method('isTopicLock')->willReturn(false);
        $registered->expects(self::once())->method('updateTopic')->with('New topic', self::isInstanceOf(DateTimeImmutable::class), null);

        $this->channelRepository
            ->expects(self::once())
            ->method('findByChannelName')
            ->with('#test')
            ->willReturn($registered);

        $this->syncCompletedRegistry
            ->expects(self::once())
            ->method('isSyncCompleted')
            ->with('#test')
            ->willReturn(true);

        $this->syncCompletedRegistry
            ->expects(self::once())
            ->method('getSyncCompletedAt')
            ->with('#test')
            ->willReturn(microtime(true) - 5);

        $this->channelRepository
            ->expects(self::once())
            ->method('save')
            ->with($registered);
        $this->channelServiceActions->expects(self::never())->method('setChannelTopic');

        $event = new ChannelTopicReceivedEvent(
            channelName: '#test',
            topic: 'New topic',
            setterNick: null,
            sourceUid: '994ZZZZZZ',
        );

        $subscriber->onTopicReceived($event);
    }
}
