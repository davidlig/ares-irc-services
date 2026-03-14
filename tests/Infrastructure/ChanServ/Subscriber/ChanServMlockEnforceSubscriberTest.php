<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\ChanServ\Subscriber;

use App\Application\ChanServ\Event\ChannelMlockUpdatedEvent;
use App\Application\Port\ActiveChannelModeSupportProviderInterface;
use App\Application\Port\BurstCompletePort;
use App\Application\Port\ChannelLookupPort;
use App\Application\Port\ChannelModeSupportInterface;
use App\Application\Port\ChannelServiceActionsPort;
use App\Application\Port\ChannelView;
use App\Domain\ChanServ\Entity\RegisteredChannel;
use App\Domain\ChanServ\Repository\RegisteredChannelRepositoryInterface;
use App\Domain\IRC\Event\ChannelModesChangedEvent;
use App\Domain\IRC\Event\ChannelSyncedEvent;
use App\Domain\IRC\Event\NetworkSyncCompleteEvent;
use App\Domain\IRC\Network\Channel;
use App\Domain\IRC\ValueObject\ChannelName;
use App\Infrastructure\ChanServ\Subscriber\ChanServMlockEnforceSubscriber;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
#[CoversClass(ChanServMlockEnforceSubscriber::class)]
final class ChanServMlockEnforceSubscriberTest extends TestCase
{
    private RegisteredChannelRepositoryInterface&MockObject $channelRepository;

    private ChannelLookupPort&MockObject $channelLookup;

    private ActiveChannelModeSupportProviderInterface&MockObject $modeSupportProvider;

    private ChannelServiceActionsPort&MockObject $channelServiceActions;

    private BurstCompletePort&MockObject $burstCompletePort;

    private ChannelModeSupportInterface&MockObject $modeSupport;

    private ChanServMlockEnforceSubscriber $subscriber;

    protected function setUp(): void
    {
        $this->channelRepository = $this->createMock(RegisteredChannelRepositoryInterface::class);
        $this->channelLookup = $this->createMock(ChannelLookupPort::class);
        $this->modeSupportProvider = $this->createMock(ActiveChannelModeSupportProviderInterface::class);
        $this->channelServiceActions = $this->createMock(ChannelServiceActionsPort::class);
        $this->burstCompletePort = $this->createMock(BurstCompletePort::class);
        $this->modeSupport = $this->createMock(ChannelModeSupportInterface::class);

        $this->subscriber = new ChanServMlockEnforceSubscriber(
            $this->channelRepository,
            $this->channelLookup,
            $this->modeSupportProvider,
            $this->channelServiceActions,
            $this->burstCompletePort,
        );
    }

    #[Test]
    public function subscribesToCorrectEvents(): void
    {
        self::assertSame(
            [
                NetworkSyncCompleteEvent::class => ['onSyncComplete', -10],
                ChannelSyncedEvent::class => ['onChannelSynced', -10],
                ChannelModesChangedEvent::class => ['onChannelModesChanged', 255],
                ChannelMlockUpdatedEvent::class => ['onMlockUpdated', 0],
            ],
            ChanServMlockEnforceSubscriber::getSubscribedEvents(),
        );
    }

    #[Test]
    public function doesNothingWhenChannelNotRegistered(): void
    {
        $channel = new Channel(new ChannelName('#test'));

        $this->burstCompletePort
            ->method('isComplete')
            ->willReturn(true);

        $this->channelRepository
            ->expects(self::once())
            ->method('findByChannelName')
            ->with('#test')
            ->willReturn(null);

        $this->channelServiceActions
            ->expects(self::never())
            ->method('setChannelModes');

        $event = new ChannelSyncedEvent($channel, channelSetupApplicable: true);
        $this->subscriber->onChannelSynced($event);
    }

    #[Test]
    public function doesNothingWhenMlockNotActive(): void
    {
        $channel = new Channel(new ChannelName('#test'));

        $registered = $this->createMock(RegisteredChannel::class);
        $registered->method('isMlockActive')->willReturn(false);

        $this->burstCompletePort
            ->method('isComplete')
            ->willReturn(true);

        $this->channelRepository
            ->expects(self::once())
            ->method('findByChannelName')
            ->with('#test')
            ->willReturn($registered);

        $this->channelServiceActions
            ->expects(self::never())
            ->method('setChannelModes');

        $event = new ChannelSyncedEvent($channel, channelSetupApplicable: true);
        $this->subscriber->onChannelSynced($event);
    }

    #[Test]
    public function doesNothingDuringInitialBurst(): void
    {
        $channel = new Channel(new ChannelName('#test'));

        $this->burstCompletePort
            ->expects(self::once())
            ->method('isComplete')
            ->willReturn(false);

        $this->channelRepository
            ->expects(self::never())
            ->method('findByChannelName');

        $this->channelServiceActions
            ->expects(self::never())
            ->method('setChannelModes');

        $event = new ChannelSyncedEvent($channel, channelSetupApplicable: true);
        $this->subscriber->onChannelSynced($event);
    }

    #[Test]
    public function doesNothingWhenChannelSetupNotApplicable(): void
    {
        $channel = new Channel(new ChannelName('#test'));

        $this->burstCompletePort
            ->expects(self::never())
            ->method('isComplete');

        $this->channelRepository
            ->expects(self::never())
            ->method('findByChannelName');

        $event = new ChannelSyncedEvent($channel, channelSetupApplicable: false);
        $this->subscriber->onChannelSynced($event);
    }

    #[Test]
    public function enforcesMlockOnChannelSyncedAfterBurstComplete(): void
    {
        $channel = new Channel(new ChannelName('#test'));
        $channel->updateModes('+ntms');

        $registered = $this->createMock(RegisteredChannel::class);
        $registered->method('isMlockActive')->willReturn(true);
        $registered->method('getMlock')->willReturn('nt');

        $view = new ChannelView(name: '#test', modes: '+ntms', topic: null, memberCount: 5);

        $this->burstCompletePort
            ->method('isComplete')
            ->willReturn(true);

        $this->channelRepository
            ->expects(self::atLeastOnce())
            ->method('findByChannelName')
            ->with('#test')
            ->willReturn($registered);

        $this->channelLookup
            ->expects(self::atLeastOnce())
            ->method('findByChannelName')
            ->willReturn($view);

        $this->modeSupportProvider
            ->method('getSupport')
            ->willReturn($this->modeSupport);

        $this->modeSupport
            ->method('getChannelSettingModesUnsetWithoutParam')
            ->willReturn(['s', 'm', 'i', 'n', 't']);
        $this->modeSupport
            ->method('getChannelSettingModesUnsetWithParam')
            ->willReturn(['k']);
        $this->modeSupport
            ->method('getChannelSettingModesWithParamOnSet')
            ->willReturn(['k', 'l']);

        $this->channelServiceActions
            ->expects(self::once())
            ->method('setChannelModes')
            ->with('#test', '-ms', []);

        $event = new ChannelSyncedEvent($channel, channelSetupApplicable: true);
        $this->subscriber->onChannelSynced($event);
    }

    #[Test]
    public function onSyncCompleteProcessesAllRegisteredChannels(): void
    {
        $registered1 = $this->createMock(RegisteredChannel::class);
        $registered1->method('isMlockActive')->willReturn(true);
        $registered1->method('getMlock')->willReturn('nt');
        $registered1->method('getName')->willReturn('#channel1');

        $registered2 = $this->createMock(RegisteredChannel::class);
        $registered2->method('isMlockActive')->willReturn(false);

        $view1 = new ChannelView(name: '#channel1', modes: '+nt', topic: null, memberCount: 5);

        $this->channelRepository
            ->expects(self::once())
            ->method('listAll')
            ->willReturn([$registered1, $registered2]);

        $this->channelLookup
            ->expects(self::once())
            ->method('findByChannelName')
            ->with('#channel1')
            ->willReturn($view1);

        $this->modeSupportProvider
            ->method('getSupport')
            ->willReturn($this->modeSupport);

        $this->modeSupport
            ->method('getChannelSettingModesUnsetWithoutParam')
            ->willReturn(['s', 'm', 'i', 'n', 't']);
        $this->modeSupport
            ->method('getChannelSettingModesUnsetWithParam')
            ->willReturn(['k']);
        $this->modeSupport
            ->method('getChannelSettingModesWithParamOnSet')
            ->willReturn(['k', 'l']);

        $this->channelServiceActions
            ->expects(self::never())
            ->method('setChannelModes');

        $connection = $this->createMock(\App\Domain\IRC\Connection\ConnectionInterface::class);
        $event = new NetworkSyncCompleteEvent($connection, '001');
        $this->subscriber->onSyncComplete($event);
    }

    #[Test]
    public function onMlockUpdatedEnforcesImmediately(): void
    {
        $registered = $this->createMock(RegisteredChannel::class);
        $registered->method('isMlockActive')->willReturn(true);
        $registered->method('getMlock')->willReturn('nt');

        $view = new ChannelView(name: '#test', modes: '+ntms', topic: null, memberCount: 5);

        $this->channelRepository
            ->method('findByChannelName')
            ->willReturn($registered);

        $this->channelLookup
            ->method('findByChannelName')
            ->willReturn($view);

        $this->modeSupportProvider
            ->method('getSupport')
            ->willReturn($this->modeSupport);

        $this->modeSupport
            ->method('getChannelSettingModesUnsetWithoutParam')
            ->willReturn(['s', 'm', 'i', 'n', 't']);
        $this->modeSupport
            ->method('getChannelSettingModesUnsetWithParam')
            ->willReturn(['k']);
        $this->modeSupport
            ->method('getChannelSettingModesWithParamOnSet')
            ->willReturn(['k', 'l']);

        $this->channelServiceActions
            ->expects(self::once())
            ->method('setChannelModes')
            ->with('#test', '-ms', []);

        $event = new ChannelMlockUpdatedEvent(channelName: '#test');
        $this->subscriber->onMlockUpdated($event);
    }
}
