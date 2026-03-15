<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\ChanServ\Subscriber;

use App\Application\Port\ActiveChannelModeSupportProviderInterface;
use App\Application\Port\ChannelLookupPort;
use App\Application\Port\ChannelModeSupportInterface;
use App\Application\Port\ChannelServiceActionsPort;
use App\Application\Port\ChannelView;
use App\Domain\ChanServ\Entity\RegisteredChannel;
use App\Domain\ChanServ\Repository\RegisteredChannelRepositoryInterface;
use App\Domain\IRC\Event\ChannelSyncedEvent;
use App\Domain\IRC\Event\NetworkSyncCompleteEvent;
use App\Domain\IRC\Network\Channel;
use App\Domain\IRC\ValueObject\ChannelName;
use App\Infrastructure\ChanServ\Subscriber\ChanServRejoinSubscriber;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

#[CoversClass(ChanServRejoinSubscriber::class)]
final class ChanServRejoinSubscriberTest extends TestCase
{
    private RegisteredChannelRepositoryInterface&MockObject $channelRepository;

    private ChannelLookupPort&MockObject $channelLookup;

    private ActiveChannelModeSupportProviderInterface&MockObject $modeSupportProvider;

    private ChannelServiceActionsPort&MockObject $channelServiceActions;

    private ChannelModeSupportInterface&MockObject $modeSupport;

    private LoggerInterface&MockObject $logger;

    private ChanServRejoinSubscriber $subscriber;

    protected function setUp(): void
    {
        $this->channelRepository = $this->createMock(RegisteredChannelRepositoryInterface::class);
        $this->channelLookup = $this->createMock(ChannelLookupPort::class);
        $this->modeSupportProvider = $this->createMock(ActiveChannelModeSupportProviderInterface::class);
        $this->channelServiceActions = $this->createMock(ChannelServiceActionsPort::class);
        $this->modeSupport = $this->createMock(ChannelModeSupportInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->subscriber = new ChanServRejoinSubscriber(
            $this->channelRepository,
            $this->channelLookup,
            $this->modeSupportProvider,
            $this->channelServiceActions,
            $this->logger,
        );
    }

    #[Test]
    public function subscribesToCorrectEvents(): void
    {
        $this->channelRepository->expects(self::never())->method('findByChannelName');
        $this->channelRepository->expects(self::never())->method('listAll');
        $this->channelLookup->expects(self::never())->method('findByChannelName');
        $this->modeSupportProvider->expects(self::never())->method('getSupport');
        $this->modeSupport->expects(self::never())->method('hasChannelRegisteredMode');
        $this->channelServiceActions->expects(self::never())->method('setChannelModes');
        $this->logger->expects(self::never())->method('warning');

        self::assertSame(
            [
                NetworkSyncCompleteEvent::class => [
                    ['onSyncCompleteSetChannelRegistered', 10],
                ],
                ChannelSyncedEvent::class => ['onChannelSyncedSetRegistered', 10],
            ],
            ChanServRejoinSubscriber::getSubscribedEvents(),
        );
    }

    #[Test]
    public function setsChannelRegisteredOnChannelSynced(): void
    {
        $channel = new Channel(new ChannelName('#test'));

        $registered = $this->createStub(RegisteredChannel::class);

        $this->channelRepository
            ->expects(self::once())
            ->method('findByChannelName')
            ->with('#test')
            ->willReturn($registered);

        $this->modeSupportProvider
            ->expects(self::once())
            ->method('getSupport')
            ->willReturn($this->modeSupport);

        $this->modeSupport
            ->expects(self::once())
            ->method('hasChannelRegisteredMode')
            ->willReturn(true);

        $this->channelServiceActions
            ->expects(self::once())
            ->method('setChannelModes')
            ->with('#test', '+r', []);
        $this->channelLookup->expects(self::never())->method('findByChannelName');
        $this->logger->expects(self::never())->method('warning');

        $event = new ChannelSyncedEvent($channel, channelSetupApplicable: true);
        $this->subscriber->onChannelSyncedSetRegistered($event);
    }

    #[Test]
    public function doesNotSetRegisteredWhenChannelNotRegistered(): void
    {
        $channel = new Channel(new ChannelName('#test'));

        $this->channelRepository
            ->expects(self::once())
            ->method('findByChannelName')
            ->with('#test')
            ->willReturn(null);

        $this->channelServiceActions
            ->expects(self::never())
            ->method('setChannelModes');
        $this->channelLookup->expects(self::never())->method('findByChannelName');
        $this->modeSupportProvider->expects(self::never())->method('getSupport');
        $this->modeSupport->expects(self::never())->method('hasChannelRegisteredMode');
        $this->logger->expects(self::never())->method('warning');

        $event = new ChannelSyncedEvent($channel, channelSetupApplicable: true);
        $this->subscriber->onChannelSyncedSetRegistered($event);
    }

    #[Test]
    public function doesNotSetRegisteredWhenNoChannelRegisteredMode(): void
    {
        $channel = new Channel(new ChannelName('#test'));

        $registered = $this->createStub(RegisteredChannel::class);

        $this->channelRepository
            ->expects(self::once())
            ->method('findByChannelName')
            ->with('#test')
            ->willReturn($registered);

        $this->modeSupportProvider
            ->expects(self::once())
            ->method('getSupport')
            ->willReturn($this->modeSupport);

        $this->modeSupport
            ->expects(self::once())
            ->method('hasChannelRegisteredMode')
            ->willReturn(false);

        $this->channelServiceActions
            ->expects(self::never())
            ->method('setChannelModes');
        $this->channelLookup->expects(self::never())->method('findByChannelName');
        $this->logger->expects(self::never())->method('warning');

        $event = new ChannelSyncedEvent($channel, channelSetupApplicable: true);
        $this->subscriber->onChannelSyncedSetRegistered($event);
    }

    #[Test]
    public function doesNotSetRegisteredWhenChannelSetupNotApplicable(): void
    {
        $channel = new Channel(new ChannelName('#test'));

        $this->channelRepository
            ->expects(self::never())
            ->method('findByChannelName');
        $this->channelLookup->expects(self::never())->method('findByChannelName');
        $this->modeSupportProvider->expects(self::never())->method('getSupport');
        $this->modeSupport->expects(self::never())->method('hasChannelRegisteredMode');
        $this->channelServiceActions->expects(self::never())->method('setChannelModes');
        $this->logger->expects(self::never())->method('warning');

        $event = new ChannelSyncedEvent($channel, channelSetupApplicable: false);
        $this->subscriber->onChannelSyncedSetRegistered($event);
    }

    #[Test]
    public function setsRegisteredOnSyncCompleteForAllChannels(): void
    {
        $registered1 = $this->createStub(RegisteredChannel::class);
        $registered1->method('getName')->willReturn('#channel1');

        $registered2 = $this->createStub(RegisteredChannel::class);
        $registered2->method('getName')->willReturn('#channel2');

        $view1 = new ChannelView(name: '#channel1', modes: '+nt', topic: null, memberCount: 5);
        $view2 = new ChannelView(name: '#channel2', modes: '+nt', topic: null, memberCount: 3);

        $this->channelRepository
            ->expects(self::once())
            ->method('listAll')
            ->willReturn([$registered1, $registered2]);

        $this->modeSupportProvider
            ->expects(self::once())
            ->method('getSupport')
            ->willReturn($this->modeSupport);

        $this->modeSupport
            ->expects(self::once())
            ->method('hasChannelRegisteredMode')
            ->willReturn(true);

        $this->channelLookup
            ->expects(self::exactly(2))
            ->method('findByChannelName')
            ->willReturnMap([
                ['#channel1', $view1],
                ['#channel2', $view2],
            ]);

        $this->channelServiceActions
            ->expects(self::exactly(2))
            ->method('setChannelModes');
        $this->logger->expects(self::never())->method('warning');

        $connection = $this->createStub(\App\Domain\IRC\Connection\ConnectionInterface::class);
        $event = new NetworkSyncCompleteEvent($connection, '001');
        $this->subscriber->onSyncCompleteSetChannelRegistered($event);
    }

    #[Test]
    public function doesNothingWhenNoRegisteredChannels(): void
    {
        $this->channelRepository
            ->expects(self::once())
            ->method('listAll')
            ->willReturn([]);

        $this->modeSupportProvider
            ->expects(self::never())
            ->method('getSupport');
        $this->channelLookup->expects(self::never())->method('findByChannelName');
        $this->modeSupport->expects(self::never())->method('hasChannelRegisteredMode');
        $this->channelServiceActions->expects(self::never())->method('setChannelModes');
        $this->logger->expects(self::never())->method('warning');

        $connection = $this->createStub(\App\Domain\IRC\Connection\ConnectionInterface::class);
        $event = new NetworkSyncCompleteEvent($connection, '001');
        $this->subscriber->onSyncCompleteSetChannelRegistered($event);
    }

    #[Test]
    public function onSyncCompleteSkipsMissingChannel(): void
    {
        $registered = $this->createStub(RegisteredChannel::class);
        $registered->method('getName')->willReturn('#channelnotonnetwork');

        $this->channelRepository
            ->expects(self::once())
            ->method('listAll')
            ->willReturn([$registered]);

        $this->modeSupportProvider
            ->expects(self::once())
            ->method('getSupport')
            ->willReturn($this->modeSupport);

        $this->modeSupport
            ->expects(self::once())
            ->method('hasChannelRegisteredMode')
            ->willReturn(true);

        $this->channelLookup
            ->expects(self::once())
            ->method('findByChannelName')
            ->with('#channelnotonnetwork')
            ->willReturn(null);

        $this->channelServiceActions
            ->expects(self::never())
            ->method('setChannelModes');
        $this->logger->expects(self::never())->method('warning');

        $connection = $this->createStub(\App\Domain\IRC\Connection\ConnectionInterface::class);
        $event = new NetworkSyncCompleteEvent($connection, '001');
        $this->subscriber->onSyncCompleteSetChannelRegistered($event);
    }

    #[Test]
    public function onSyncCompleteSkipsWhenNoRegisteredMode(): void
    {
        $registered = $this->createStub(RegisteredChannel::class);
        $registered->method('getName')->willReturn('#test');

        $this->channelRepository
            ->expects(self::once())
            ->method('listAll')
            ->willReturn([$registered]);

        $this->modeSupportProvider
            ->expects(self::once())
            ->method('getSupport')
            ->willReturn($this->modeSupport);

        $this->modeSupport
            ->expects(self::once())
            ->method('hasChannelRegisteredMode')
            ->willReturn(false);

        $this->channelLookup->expects(self::never())->method('findByChannelName');
        $this->channelServiceActions->expects(self::never())->method('setChannelModes');
        $this->logger->expects(self::never())->method('warning');

        $connection = $this->createStub(\App\Domain\IRC\Connection\ConnectionInterface::class);
        $event = new NetworkSyncCompleteEvent($connection, '001');
        $this->subscriber->onSyncCompleteSetChannelRegistered($event);
    }
}
