<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\ChanServ\Subscriber;

use App\Application\Port\ChannelServiceActionsPort;
use App\Application\Port\ChannelSyncCompletedRegistryInterface;
use App\Domain\ChanServ\Entity\RegisteredChannel;
use App\Domain\ChanServ\Repository\RegisteredChannelRepositoryInterface;
use App\Domain\IRC\ValueObject\ChannelName;
use App\Infrastructure\ChanServ\Subscriber\ChanServTopicSyncSubscriber;
use App\Infrastructure\IRC\Network\Event\ChannelTopicReceivedEvent;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

#[CoversClass(ChanServTopicSyncSubscriber::class)]
final class ChanServTopicSyncSubscriberTest extends TestCase
{
    private RegisteredChannelRepositoryInterface&MockObject $channelRepository;

    private ChannelServiceActionsPort&MockObject $channelServiceActions;

    private ChannelSyncCompletedRegistryInterface&MockObject $syncCompletedRegistry;

    private LoggerInterface&MockObject $logger;

    private ChanServTopicSyncSubscriber $subscriber;

    protected function setUp(): void
    {
        $this->channelRepository = $this->createMock(RegisteredChannelRepositoryInterface::class);
        $this->channelServiceActions = $this->createMock(ChannelServiceActionsPort::class);
        $this->syncCompletedRegistry = $this->createMock(ChannelSyncCompletedRegistryInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->subscriber = new ChanServTopicSyncSubscriber(
            $this->channelRepository,
            $this->channelServiceActions,
            $this->syncCompletedRegistry,
            'ChanServ',
            'NickServ',
            $this->logger,
        );
    }

    #[Test]
    public function subscribesToChannelTopicReceivedEvent(): void
    {
        $this->channelRepository->expects(self::never())->method('findByChannelName');
        $this->channelServiceActions->expects(self::never())->method('setChannelTopic');
        $this->syncCompletedRegistry->expects(self::never())->method('isSyncCompleted');
        $this->logger->expects(self::never())->method('warning');

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
        $this->logger->expects(self::never())->method('warning');

        $event = new ChannelTopicReceivedEvent(
            channelName: new ChannelName('#test'),
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
        $this->logger->expects(self::never())->method('warning');

        $event = new ChannelTopicReceivedEvent(
            channelName: new ChannelName('#test'),
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
        $this->logger->expects(self::never())->method('warning');

        $event = new ChannelTopicReceivedEvent(
            channelName: new ChannelName('#test'),
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

        $this->channelRepository
            ->expects(self::never())
            ->method('save');
        $this->channelServiceActions->expects(self::never())->method('setChannelTopic');
        $this->logger->expects(self::never())->method('warning');
        $this->logger->expects(self::atLeastOnce())->method('debug');

        $event = new ChannelTopicReceivedEvent(
            channelName: new ChannelName('#test'),
            topic: 'New topic',
        );

        $this->subscriber->onTopicReceived($event);
    }

    #[Test]
    public function persistsTopicWhenSyncCompleted(): void
    {
        $registered = $this->createMock(RegisteredChannel::class);
        $registered->expects(self::atLeastOnce())->method('isTopicLock')->willReturn(false);
        $registered->expects(self::once())->method('updateTopic')->with('New topic', 'User');

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
        $this->logger->expects(self::never())->method('warning');
        $this->logger->expects(self::atLeastOnce())->method('debug');

        $event = new ChannelTopicReceivedEvent(
            channelName: new ChannelName('#test'),
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
        $this->logger->expects(self::never())->method('warning');
        $this->logger->expects(self::atLeastOnce())->method('debug');

        $event = new ChannelTopicReceivedEvent(
            channelName: new ChannelName('#test'),
            topic: 'New topic',
        );

        $this->subscriber->onTopicReceived($event);
    }

    #[Test]
    public function ignoresSetterNickWhenServicesUser(): void
    {
        $registered = $this->createMock(RegisteredChannel::class);
        $registered->expects(self::atLeastOnce())->method('isTopicLock')->willReturn(false);
        $registered->expects(self::once())->method('updateTopic')->with('New topic', null);

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
        $this->logger->expects(self::never())->method('warning');
        $this->logger->expects(self::atLeastOnce())->method('debug');

        $event = new ChannelTopicReceivedEvent(
            channelName: new ChannelName('#test'),
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
        $this->logger->expects(self::never())->method('warning');

        $event = new ChannelTopicReceivedEvent(
            channelName: new ChannelName('#test'),
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
        $registered->expects(self::once())->method('updateTopic')->with('New topic', null);

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
        $this->logger->expects(self::never())->method('warning');

        $event = new ChannelTopicReceivedEvent(
            channelName: new ChannelName('#test'),
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
        $registered->expects(self::once())->method('updateTopic')->with('New topic', null);

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
        $this->logger->expects(self::never())->method('warning');

        $event = new ChannelTopicReceivedEvent(
            channelName: new ChannelName('#test'),
            topic: 'New topic',
            setterNick: 'NickServ',
        );

        $this->subscriber->onTopicReceived($event);
    }

    #[Test]
    public function persistsTopicWithNullSetterNick(): void
    {
        $registered = $this->createMock(RegisteredChannel::class);
        $registered->expects(self::atLeastOnce())->method('isTopicLock')->willReturn(false);
        $registered->expects(self::once())->method('updateTopic')->with('New topic', null);

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
        $this->logger->expects(self::never())->method('warning');
        $this->logger->expects(self::atLeastOnce())->method('debug');

        $event = new ChannelTopicReceivedEvent(
            channelName: new ChannelName('#test'),
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
        $registered->expects(self::once())->method('updateTopic')->with('New topic', 'TestUser');

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
        $this->logger->expects(self::never())->method('warning');
        $this->logger->expects(self::atLeastOnce())->method('debug');

        $event = new ChannelTopicReceivedEvent(
            channelName: new ChannelName('#test'),
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
        $this->logger->expects(self::never())->method('warning');
        $this->logger->expects(self::atLeastOnce())->method('debug');

        $event = new ChannelTopicReceivedEvent(
            channelName: new ChannelName('#test'),
            topic: 'New topic',
        );

        $this->subscriber->onTopicReceived($event);
    }

    #[Test]
    public function onTopicReceivedSkipsSuspendedChannel(): void
    {
        $registered = $this->createStub(RegisteredChannel::class);
        $registered->method('isSuspended')->willReturn(true);

        $this->channelRepository
            ->expects(self::once())
            ->method('findByChannelName')
            ->with('#test')
            ->willReturn($registered);
        $this->channelServiceActions
            ->expects(self::never())
            ->method('setChannelTopic');
        $this->syncCompletedRegistry->expects(self::never())->method('isSyncCompleted');
        $this->logger->expects(self::never())->method('warning');

        $event = new ChannelTopicReceivedEvent(
            channelName: new ChannelName('#test'),
            topic: 'New topic',
            setterNick: 'User',
        );
        $this->subscriber->onTopicReceived($event);
    }
}
