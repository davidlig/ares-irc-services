<?php

declare(strict_types=1);

namespace App\Tests\ChanServ\Adapter\In\Event;

use App\ChanServ\Adapter\In\Event\ChanServTopicApplySubscriber;
use App\ChanServ\Adapter\Out\Network\IrcChannelTopicActions;
use App\ChanServ\Adapter\Out\Network\IrcChannelTopicNetworkQuery;
use App\ChanServ\Application\Model\ChannelTopicNetworkState;
use App\ChanServ\Application\Port\Out\RegisteredChannelRepositoryInterface;
use App\ChanServ\Application\UseCase\ApplyStoredTopic\ApplyStoredChannelTopic;
use App\ChanServ\Application\UseCase\ApplyStoredTopic\ApplyStoredChannelTopicHandler;
use App\ChanServ\Application\UseCase\ApplyStoredTopic\StoredTopicApplicationTrigger;
use App\ChanServ\Domain\Entity\RegisteredChannel;
use App\Irc\Application\Port\In\ChannelLookupPort;
use App\Irc\Application\Port\In\ChannelView;
use App\Irc\Application\PublishedEvent\ChannelSynchronizedEvent;
use App\Irc\Application\PublishedEvent\NetworkSynchronizationCompletedEvent;
use App\Shared\Application\Port\ChannelServiceActionsPort;
use App\Shared\Application\Port\ChannelSyncCompletedRegistryInterface;
use App\Shared\Application\Port\UidResolverInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[CoversClass(ChanServTopicApplySubscriber::class)]
#[CoversClass(IrcChannelTopicActions::class)]
#[CoversClass(IrcChannelTopicNetworkQuery::class)]
#[CoversClass(ChannelTopicNetworkState::class)]
#[CoversClass(ApplyStoredChannelTopic::class)]
#[CoversClass(ApplyStoredChannelTopicHandler::class)]
#[CoversClass(StoredTopicApplicationTrigger::class)]
final class ChanServTopicApplySubscriberTest extends TestCase
{
    private MockObject&RegisteredChannelRepositoryInterface $channelRepository;

    private ChannelLookupPort&MockObject $channelLookup;

    private ChannelServiceActionsPort&MockObject $channelServiceActions;

    private ChanServTopicApplySubscriber $subscriber;

    protected function setUp(): void
    {
        $this->channelRepository = $this->createMock(RegisteredChannelRepositoryInterface::class);
        $this->channelLookup = $this->createMock(ChannelLookupPort::class);
        $this->channelServiceActions = $this->createMock(ChannelServiceActionsPort::class);
        $this->subscriber = new ChanServTopicApplySubscriber(
            new ApplyStoredChannelTopicHandler(
                $this->channelRepository,
                new IrcChannelTopicNetworkQuery(
                    $this->channelLookup,
                    $this->createStub(ChannelSyncCompletedRegistryInterface::class),
                    $this->createStub(UidResolverInterface::class),
                ),
                new IrcChannelTopicActions($this->channelServiceActions),
            ),
        );
    }

    #[Test]
    public function subscribesToCorrectEvents(): void
    {
        $this->channelRepository->expects(self::never())->method('findByChannelName');
        $this->channelLookup->expects(self::never())->method('findByChannelName');
        $this->channelServiceActions->expects(self::never())->method('setChannelTopic');

        self::assertSame(
            [
                ChannelSynchronizedEvent::class => ['onChannelSynced', -20],
                NetworkSynchronizationCompletedEvent::class => ['onSyncComplete', -20],
            ],
            ChanServTopicApplySubscriber::getSubscribedEvents(),
        );
    }

    #[Test]
    public function appliesStoredTopicOnChannelSyncedWhenDifferent(): void
    {
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

        $event = new ChannelSynchronizedEvent('#test', channelSetupApplicable: true);
        $this->subscriber->onChannelSynced($event);
    }

    #[Test]
    public function doesNotApplyTopicWhenChannelNotRegistered(): void
    {
        $this->channelRepository
            ->expects(self::once())
            ->method('findByChannelName')
            ->with('#test')
            ->willReturn(null);

        $this->channelServiceActions
            ->expects(self::never())
            ->method('setChannelTopic');
        $this->channelLookup->expects(self::never())->method('findByChannelName');

        $event = new ChannelSynchronizedEvent('#test', channelSetupApplicable: true);
        $this->subscriber->onChannelSynced($event);
    }

    #[Test]
    public function doesNotApplyTopicWhenNoStoredTopic(): void
    {
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

        $event = new ChannelSynchronizedEvent('#test', channelSetupApplicable: true);
        $this->subscriber->onChannelSynced($event);
    }

    #[Test]
    public function appliesStoredTopicEvenWhenChannelTopicsMatchOnInitialSetup(): void
    {
        $registered = $this->createStub(RegisteredChannel::class);
        $registered->method('getTopic')->willReturn('Same topic');

        $this->channelRepository
            ->expects(self::once())
            ->method('findByChannelName')
            ->with('#test')
            ->willReturn($registered);

        $this->channelServiceActions
            ->expects(self::once())
            ->method('setChannelTopic')
            ->with('#test', 'Same topic');
        $this->channelLookup->expects(self::never())->method('findByChannelName');

        $event = new ChannelSynchronizedEvent('#test', channelSetupApplicable: true);
        $this->subscriber->onChannelSynced($event);
    }

    #[Test]
    public function doesNotApplyTopicWhenSetupNotApplicableAndTopicsMatch(): void
    {
        $registered = $this->createStub(RegisteredChannel::class);
        $registered->method('isSuspended')->willReturn(false);
        $registered->method('isForbidden')->willReturn(false);
        $registered->method('getTopic')->willReturn('Same topic');

        $this->channelRepository
            ->expects(self::once())
            ->method('findByChannelName')
            ->with('#test')
            ->willReturn($registered);

        $view = new ChannelView('#test', '+nt', 'Same topic', 1);
        $this->channelLookup
            ->expects(self::once())
            ->method('findByChannelName')
            ->with('#test')
            ->willReturn($view);

        $this->channelServiceActions
            ->expects(self::never())
            ->method('setChannelTopic');

        $event = new ChannelSynchronizedEvent('#test', channelSetupApplicable: false);
        $this->subscriber->onChannelSynced($event);
    }

    #[Test]
    public function appliesTopicWhenSetupNotApplicableAndTopicsDiffer(): void
    {
        $registered = $this->createStub(RegisteredChannel::class);
        $registered->method('isSuspended')->willReturn(false);
        $registered->method('isForbidden')->willReturn(false);
        $registered->method('getTopic')->willReturn('Stored topic');

        $this->channelRepository
            ->expects(self::once())
            ->method('findByChannelName')
            ->with('#test')
            ->willReturn($registered);

        $view = new ChannelView('#test', '+nt', 'Current topic from network', 1);
        $this->channelLookup
            ->expects(self::once())
            ->method('findByChannelName')
            ->with('#test')
            ->willReturn($view);

        $this->channelServiceActions
            ->expects(self::once())
            ->method('setChannelTopic')
            ->with('#test', 'Stored topic');

        $event = new ChannelSynchronizedEvent('#test', channelSetupApplicable: false);
        $this->subscriber->onChannelSynced($event);
    }

    #[Test]
    public function appliesTopicWhenSetupNotApplicableAndChannelViewNotFound(): void
    {
        $registered = $this->createStub(RegisteredChannel::class);
        $registered->method('isSuspended')->willReturn(false);
        $registered->method('isForbidden')->willReturn(false);
        $registered->method('getTopic')->willReturn('Stored topic');

        $this->channelRepository
            ->expects(self::once())
            ->method('findByChannelName')
            ->with('#test')
            ->willReturn($registered);

        $this->channelLookup
            ->expects(self::once())
            ->method('findByChannelName')
            ->with('#test')
            ->willReturn(null);

        $this->channelServiceActions
            ->expects(self::once())
            ->method('setChannelTopic')
            ->with('#test', 'Stored topic');

        $event = new ChannelSynchronizedEvent('#test', channelSetupApplicable: false);
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

        $event = new NetworkSynchronizationCompletedEvent('001');
        $this->subscriber->onSyncComplete($event);
    }

    #[Test]
    public function onSyncCompleteSkipsChannelWhenLookupReturnsNull(): void
    {
        $registered = $this->createStub(RegisteredChannel::class);
        $registered->method('getName')->willReturn('#channel1');
        $registered->method('getTopic')->willReturn('Topic 1');

        $this->channelRepository
            ->expects(self::once())
            ->method('listAll')
            ->willReturn([$registered]);

        $this->channelLookup
            ->expects(self::once())
            ->method('findByChannelName')
            ->with('#channel1')
            ->willReturn(null);

        $this->channelServiceActions
            ->expects(self::never())
            ->method('setChannelTopic');

        $event = new NetworkSynchronizationCompletedEvent('001');
        $this->subscriber->onSyncComplete($event);
    }

    #[Test]
    public function onSyncCompleteSkipsWhenNoStoredTopic(): void
    {
        $registered = $this->createStub(RegisteredChannel::class);
        $registered->method('getName')->willReturn('#channel1');
        $registered->method('getTopic')->willReturn(null);

        $this->channelRepository
            ->expects(self::once())
            ->method('listAll')
            ->willReturn([$registered]);

        $this->channelLookup
            ->expects(self::never())
            ->method('findByChannelName');

        $this->channelServiceActions
            ->expects(self::never())
            ->method('setChannelTopic');

        $event = new NetworkSynchronizationCompletedEvent('001');
        $this->subscriber->onSyncComplete($event);
    }

    #[Test]
    public function onSyncCompleteSkipsWhenTopicsMatch(): void
    {
        $registered = $this->createStub(RegisteredChannel::class);
        $registered->method('getName')->willReturn('#channel1');
        $registered->method('getTopic')->willReturn('Same topic');

        $view = new ChannelView(name: '#channel1', modes: '+nt', topic: 'Same topic', memberCount: 5);

        $this->channelRepository
            ->expects(self::once())
            ->method('listAll')
            ->willReturn([$registered]);

        $this->channelLookup
            ->expects(self::once())
            ->method('findByChannelName')
            ->with('#channel1')
            ->willReturn($view);

        $this->channelServiceActions
            ->expects(self::never())
            ->method('setChannelTopic');

        $event = new NetworkSynchronizationCompletedEvent('001');
        $this->subscriber->onSyncComplete($event);
    }

    #[Test]
    public function onSyncCompleteWithEmptyChannelList(): void
    {
        $this->channelRepository
            ->expects(self::once())
            ->method('listAll')
            ->willReturn([]);

        $this->channelLookup
            ->expects(self::never())
            ->method('findByChannelName');

        $this->channelServiceActions
            ->expects(self::never())
            ->method('setChannelTopic');

        $event = new NetworkSynchronizationCompletedEvent('001');
        $this->subscriber->onSyncComplete($event);
    }

    #[Test]
    public function onChannelSyncedAppliesTopicWhenCurrentTopicIsNull(): void
    {
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

        $event = new ChannelSynchronizedEvent('#test', channelSetupApplicable: true);
        $this->subscriber->onChannelSynced($event);
    }

    #[Test]
    public function onChannelSyncedHandlesEmptyStringTopic(): void
    {
        $registered = $this->createStub(RegisteredChannel::class);
        $registered->method('getTopic')->willReturn('Stored topic');

        $this->channelRepository
            ->expects(self::once())
            ->method('findByChannelName')
            ->with('#test')
            ->willReturn($registered);

        $this->channelServiceActions
            ->expects(self::once())
            ->method('setChannelTopic')
            ->with('#test', 'Stored topic');
        $this->channelLookup->expects(self::never())->method('findByChannelName');

        $event = new ChannelSynchronizedEvent('#test', channelSetupApplicable: true);
        $this->subscriber->onChannelSynced($event);
    }

    #[Test]
    public function onChannelSyncedSkipsSuspendedChannel(): void
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
        $this->channelLookup->expects(self::never())->method('findByChannelName');

        $event = new ChannelSynchronizedEvent('#test', channelSetupApplicable: true);
        $this->subscriber->onChannelSynced($event);
    }

    #[Test]
    public function onSyncCompleteSkipsSuspendedChannel(): void
    {
        $registered = $this->createStub(RegisteredChannel::class);
        $registered->method('isSuspended')->willReturn(true);
        $registered->method('isBlocked')->willReturn(true);
        $registered->method('getName')->willReturn('#test');
        $registered->method('getTopic')->willReturn('Some topic');

        $this->channelRepository
            ->expects(self::once())
            ->method('listAll')
            ->willReturn([$registered]);
        $this->channelServiceActions
            ->expects(self::never())
            ->method('setChannelTopic');
        $this->channelLookup->expects(self::never())->method('findByChannelName');

        $event = new NetworkSynchronizationCompletedEvent('001');
        $this->subscriber->onSyncComplete($event);
    }
}
