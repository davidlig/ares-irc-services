<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\ChanServ\Subscriber;

use App\Application\Port\ChannelLookupPort;
use App\Application\Port\ChannelServiceActionsPort;
use App\Application\Port\ChannelView;
use App\Domain\ChanServ\Entity\RegisteredChannel;
use App\Domain\ChanServ\Repository\RegisteredChannelRepositoryInterface;
use App\Domain\IRC\Event\ChannelSyncedEvent;
use App\Domain\IRC\Event\NetworkSyncCompleteEvent;
use App\Domain\IRC\Network\Channel;
use App\Domain\IRC\ValueObject\ChannelName;
use App\Infrastructure\ChanServ\Subscriber\ChanServTopicApplySubscriber;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

#[CoversClass(ChanServTopicApplySubscriber::class)]
final class ChanServTopicApplySubscriberTest extends TestCase
{
    private RegisteredChannelRepositoryInterface&MockObject $channelRepository;

    private ChannelLookupPort&MockObject $channelLookup;

    private ChannelServiceActionsPort&MockObject $channelServiceActions;

    private LoggerInterface&MockObject $logger;

    private ChanServTopicApplySubscriber $subscriber;

    protected function setUp(): void
    {
        $this->channelRepository = $this->createMock(RegisteredChannelRepositoryInterface::class);
        $this->channelLookup = $this->createMock(ChannelLookupPort::class);
        $this->channelServiceActions = $this->createMock(ChannelServiceActionsPort::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->subscriber = new ChanServTopicApplySubscriber(
            $this->channelRepository,
            $this->channelLookup,
            $this->channelServiceActions,
            $this->logger,
        );
    }

    #[Test]
    public function subscribesToCorrectEvents(): void
    {
        $this->channelRepository->expects(self::never())->method('findByChannelName');
        $this->channelLookup->expects(self::never())->method('findByChannelName');
        $this->channelServiceActions->expects(self::never())->method('setChannelTopic');
        $this->logger->expects(self::never())->method('warning');

        self::assertSame(
            [
                ChannelSyncedEvent::class => ['onChannelSynced', -20],
                NetworkSyncCompleteEvent::class => ['onSyncComplete', -20],
            ],
            ChanServTopicApplySubscriber::getSubscribedEvents(),
        );
    }

    #[Test]
    public function appliesStoredTopicOnChannelSyncedWhenDifferent(): void
    {
        $channel = new Channel(new ChannelName('#test'));
        $channel->updateTopic('Current topic from network');

        $registered = $this->createStub(RegisteredChannel::class);
        $registered->method('getTopic')->willReturn('Stored topic from DB');

        $this->channelRepository
            ->expects(self::once())
            ->method('findByChannelName')
            ->with('#test')
            ->willReturn($registered);

        $this->channelServiceActions
            ->expects(self::once())
            ->method('setChannelTopic')
            ->with('#test', 'Stored topic from DB');
        $this->channelLookup->expects(self::never())->method('findByChannelName');
        $this->logger->expects(self::never())->method('warning');

        $event = new ChannelSyncedEvent($channel, channelSetupApplicable: true);
        $this->subscriber->onChannelSynced($event);
    }

    #[Test]
    public function doesNotApplyTopicWhenChannelNotRegistered(): void
    {
        $channel = new Channel(new ChannelName('#test'));

        $this->channelRepository
            ->expects(self::once())
            ->method('findByChannelName')
            ->with('#test')
            ->willReturn(null);

        $this->channelServiceActions
            ->expects(self::never())
            ->method('setChannelTopic');
        $this->channelLookup->expects(self::never())->method('findByChannelName');
        $this->logger->expects(self::never())->method('warning');

        $event = new ChannelSyncedEvent($channel, channelSetupApplicable: true);
        $this->subscriber->onChannelSynced($event);
    }

    #[Test]
    public function doesNotApplyTopicWhenNoStoredTopic(): void
    {
        $channel = new Channel(new ChannelName('#test'));

        $registered = $this->createStub(RegisteredChannel::class);
        $registered->method('getTopic')->willReturn(null);

        $this->channelRepository
            ->expects(self::once())
            ->method('findByChannelName')
            ->with('#test')
            ->willReturn($registered);

        $this->channelServiceActions
            ->expects(self::never())
            ->method('setChannelTopic');
        $this->channelLookup->expects(self::never())->method('findByChannelName');
        $this->logger->expects(self::never())->method('warning');

        $event = new ChannelSyncedEvent($channel, channelSetupApplicable: true);
        $this->subscriber->onChannelSynced($event);
    }

    #[Test]
    public function doesNotApplyTopicWhenTopicsMatch(): void
    {
        $channel = new Channel(new ChannelName('#test'));
        $channel->updateTopic('Same topic');

        $registered = $this->createStub(RegisteredChannel::class);
        $registered->method('getTopic')->willReturn('Same topic');

        $this->channelRepository
            ->expects(self::once())
            ->method('findByChannelName')
            ->with('#test')
            ->willReturn($registered);

        $this->channelServiceActions
            ->expects(self::never())
            ->method('setChannelTopic');
        $this->channelLookup->expects(self::never())->method('findByChannelName');
        $this->logger->expects(self::never())->method('warning');

        $event = new ChannelSyncedEvent($channel, channelSetupApplicable: true);
        $this->subscriber->onChannelSynced($event);
    }

    #[Test]
    public function doesNotApplyTopicWhenChannelSetupNotApplicable(): void
    {
        $channel = new Channel(new ChannelName('#test'));

        $this->channelRepository
            ->expects(self::never())
            ->method('findByChannelName');
        $this->channelLookup->expects(self::never())->method('findByChannelName');
        $this->channelServiceActions->expects(self::never())->method('setChannelTopic');
        $this->logger->expects(self::never())->method('warning');

        $event = new ChannelSyncedEvent($channel, channelSetupApplicable: false);
        $this->subscriber->onChannelSynced($event);
    }

    #[Test]
    public function appliesTopicOnSyncCompleteForAllRegisteredChannels(): void
    {
        $registered1 = $this->createStub(RegisteredChannel::class);
        $registered1->method('getName')->willReturn('#channel1');
        $registered1->method('getTopic')->willReturn('Topic 1');

        $registered2 = $this->createStub(RegisteredChannel::class);
        $registered2->method('getName')->willReturn('#channel2');
        $registered2->method('getTopic')->willReturn('Topic 2');

        $view1 = new ChannelView(name: '#channel1', modes: '+nt', topic: 'Old topic', memberCount: 5);
        $view2 = new ChannelView(name: '#channel2', modes: '+nt', topic: null, memberCount: 3);

        $this->channelRepository
            ->expects(self::once())
            ->method('listAll')
            ->willReturn([$registered1, $registered2]);

        $this->channelLookup
            ->expects(self::exactly(2))
            ->method('findByChannelName')
            ->willReturnMap([
                ['#channel1', $view1],
                ['#channel2', $view2],
            ]);

        $this->channelServiceActions
            ->expects(self::exactly(2))
            ->method('setChannelTopic');
        $this->logger->expects(self::never())->method('warning');

        $connection = $this->createStub(\App\Domain\IRC\Connection\ConnectionInterface::class);
        $event = new NetworkSyncCompleteEvent($connection, '001');
        $this->subscriber->onSyncComplete($event);
    }
}
