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
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

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
        $this->channelRepository->expects(self::never())->method('findByChannelName');
        $this->channelLookup->expects(self::never())->method('findByChannelName');
        $this->modeSupportProvider->expects(self::never())->method('getSupport');
        $this->channelServiceActions->expects(self::never())->method('setChannelModes');
        $this->burstCompletePort->expects(self::never())->method('isComplete');
        $this->modeSupport->expects(self::never())->method('getChannelSettingModesUnsetWithoutParam');

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
            ->expects(self::atLeastOnce())
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
        $this->channelLookup->expects(self::never())->method('findByChannelName');
        $this->modeSupportProvider->expects(self::never())->method('getSupport');
        $this->modeSupport->expects(self::never())->method('getChannelSettingModesUnsetWithoutParam');

        $event = new ChannelSyncedEvent($channel, channelSetupApplicable: true);
        $this->subscriber->onChannelSynced($event);
    }

    #[Test]
    public function doesNothingWhenMlockNotActive(): void
    {
        $channel = new Channel(new ChannelName('#test'));

        $registered = $this->createStub(RegisteredChannel::class);
        $registered->method('isMlockActive')->willReturn(false);

        $this->burstCompletePort
            ->expects(self::atLeastOnce())
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
        $this->channelLookup->expects(self::never())->method('findByChannelName');
        $this->modeSupportProvider->expects(self::never())->method('getSupport');
        $this->modeSupport->expects(self::never())->method('getChannelSettingModesUnsetWithoutParam');

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
        $this->channelLookup->expects(self::never())->method('findByChannelName');
        $this->modeSupportProvider->expects(self::never())->method('getSupport');
        $this->modeSupport->expects(self::never())->method('getChannelSettingModesUnsetWithoutParam');

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
        $this->channelLookup->expects(self::never())->method('findByChannelName');
        $this->modeSupportProvider->expects(self::never())->method('getSupport');
        $this->modeSupport->expects(self::never())->method('getChannelSettingModesUnsetWithoutParam');
        $this->channelServiceActions->expects(self::never())->method('setChannelModes');
        $this->burstCompletePort->expects(self::never())->method('isComplete');

        $event = new ChannelSyncedEvent($channel, channelSetupApplicable: false);
        $this->subscriber->onChannelSynced($event);
    }

    #[Test]
    public function enforcesMlockOnChannelSyncedAfterBurstComplete(): void
    {
        $channel = new Channel(new ChannelName('#test'));
        $channel->updateModes('+ntms');

        $registered = $this->createStub(RegisteredChannel::class);
        $registered->method('isMlockActive')->willReturn(true);
        $registered->method('getMlock')->willReturn('nt');

        $view = new ChannelView(name: '#test', modes: '+ntms', topic: null, memberCount: 5);

        $this->burstCompletePort
            ->expects(self::atLeastOnce())
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
            ->expects(self::atLeastOnce())
            ->method('getSupport')
            ->willReturn($this->modeSupport);

        $this->modeSupport
            ->expects(self::atLeastOnce())
            ->method('getChannelSettingModesUnsetWithoutParam')
            ->willReturn(['s', 'm', 'i', 'n', 't']);
        $this->modeSupport
            ->expects(self::atLeastOnce())
            ->method('getChannelSettingModesUnsetWithParam')
            ->willReturn(['k']);
        $this->modeSupport
            ->expects(self::atLeastOnce())
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
        $registered1 = $this->createStub(RegisteredChannel::class);
        $registered1->method('isMlockActive')->willReturn(true);
        $registered1->method('getMlock')->willReturn('nt');
        $registered1->method('getName')->willReturn('#channel1');

        $registered2 = $this->createStub(RegisteredChannel::class);
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
            ->expects(self::atLeastOnce())
            ->method('getSupport')
            ->willReturn($this->modeSupport);

        $this->modeSupport
            ->expects(self::atLeastOnce())
            ->method('getChannelSettingModesUnsetWithoutParam')
            ->willReturn(['s', 'm', 'i', 'n', 't']);
        $this->modeSupport
            ->expects(self::atLeastOnce())
            ->method('getChannelSettingModesUnsetWithParam')
            ->willReturn(['k']);
        $this->modeSupport
            ->expects(self::atLeastOnce())
            ->method('getChannelSettingModesWithParamOnSet')
            ->willReturn(['k', 'l']);

        $this->channelServiceActions
            ->expects(self::never())
            ->method('setChannelModes');
        $this->burstCompletePort->expects(self::never())->method('isComplete');

        $connection = $this->createStub(\App\Domain\IRC\Connection\ConnectionInterface::class);
        $event = new NetworkSyncCompleteEvent($connection, '001');
        $this->subscriber->onSyncComplete($event);
    }

    #[Test]
    public function onMlockUpdatedEnforcesImmediately(): void
    {
        $registered = $this->createStub(RegisteredChannel::class);
        $registered->method('isMlockActive')->willReturn(true);
        $registered->method('getMlock')->willReturn('nt');

        $view = new ChannelView(name: '#test', modes: '+ntms', topic: null, memberCount: 5);

        $this->channelRepository
            ->expects(self::atLeastOnce())
            ->method('findByChannelName')
            ->willReturn($registered);

        $this->channelLookup
            ->expects(self::atLeastOnce())
            ->method('findByChannelName')
            ->willReturn($view);

        $this->modeSupportProvider
            ->expects(self::atLeastOnce())
            ->method('getSupport')
            ->willReturn($this->modeSupport);

        $this->modeSupport
            ->expects(self::atLeastOnce())
            ->method('getChannelSettingModesUnsetWithoutParam')
            ->willReturn(['s', 'm', 'i', 'n', 't']);
        $this->modeSupport
            ->expects(self::atLeastOnce())
            ->method('getChannelSettingModesUnsetWithParam')
            ->willReturn(['k']);
        $this->modeSupport
            ->expects(self::atLeastOnce())
            ->method('getChannelSettingModesWithParamOnSet')
            ->willReturn(['k', 'l']);

        $this->channelServiceActions
            ->expects(self::once())
            ->method('setChannelModes')
            ->with('#test', '-ms', []);
        $this->burstCompletePort->expects(self::never())->method('isComplete');

        $event = new ChannelMlockUpdatedEvent(channelName: '#test');
        $this->subscriber->onMlockUpdated($event);
    }

    #[Test]
    public function onChannelModesChangedWhenChannelNotRegistered(): void
    {
        $channel = new Channel(new ChannelName('#test'));
        $channel->updateModes('+nt');

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
        $this->modeSupport->expects(self::never())->method('getChannelSettingModesUnsetWithoutParam');
        $this->burstCompletePort->expects(self::never())->method('isComplete');

        $event = new ChannelModesChangedEvent($channel, '+nt', []);
        $this->subscriber->onChannelModesChanged($event);
    }

    #[Test]
    public function onChannelModesChangedWhenMlockNotActive(): void
    {
        $channel = new Channel(new ChannelName('#test'));
        $channel->updateModes('+nt');

        $registered = $this->createStub(RegisteredChannel::class);
        $registered->method('isMlockActive')->willReturn(false);

        $this->channelRepository
            ->expects(self::once())
            ->method('findByChannelName')
            ->with('#test')
            ->willReturn($registered);

        $this->channelServiceActions
            ->expects(self::never())
            ->method('setChannelModes');
        $this->channelLookup->expects(self::never())->method('findByChannelName');
        $this->modeSupportProvider->expects(self::never())->method('getSupport');
        $this->modeSupport->expects(self::never())->method('getChannelSettingModesUnsetWithoutParam');
        $this->burstCompletePort->expects(self::never())->method('isComplete');

        $event = new ChannelModesChangedEvent($channel, '+nt', []);
        $this->subscriber->onChannelModesChanged($event);
    }

    #[Test]
    public function onChannelModesChangedWhenChannelLookupNull(): void
    {
        $channel = new Channel(new ChannelName('#test'));
        $channel->updateModes('+nt');

        $registered = $this->createStub(RegisteredChannel::class);
        $registered->method('isMlockActive')->willReturn(true);
        $registered->method('getMlock')->willReturn('nt');

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
            ->expects(self::never())
            ->method('setChannelModes');
        $this->modeSupportProvider->expects(self::never())->method('getSupport');
        $this->modeSupport->expects(self::never())->method('getChannelSettingModesUnsetWithoutParam');
        $this->burstCompletePort->expects(self::never())->method('isComplete');

        $event = new ChannelModesChangedEvent($channel, '+nt', []);
        $this->subscriber->onChannelModesChanged($event);
    }

    #[Test]
    public function onChannelModesChangedEnforcesMlock(): void
    {
        $channel = new Channel(new ChannelName('#test'));
        $channel->updateModes('+ntms');

        $registered = $this->createStub(RegisteredChannel::class);
        $registered->method('isMlockActive')->willReturn(true);
        $registered->method('getMlock')->willReturn('nt');

        $view = new ChannelView(name: '#test', modes: '+ntms', topic: null, memberCount: 5);

        $this->channelRepository
            ->expects(self::once())
            ->method('findByChannelName')
            ->with('#test')
            ->willReturn($registered);

        $this->channelLookup
            ->expects(self::once())
            ->method('findByChannelName')
            ->with('#test')
            ->willReturn($view);

        $this->modeSupportProvider
            ->expects(self::once())
            ->method('getSupport')
            ->willReturn($this->modeSupport);

        $this->modeSupport
            ->expects(self::once())
            ->method('getChannelSettingModesUnsetWithoutParam')
            ->willReturn(['s', 'm', 'i', 'n', 't']);
        $this->modeSupport
            ->expects(self::once())
            ->method('getChannelSettingModesUnsetWithParam')
            ->willReturn(['k']);
        $this->modeSupport
            ->expects(self::once())
            ->method('getChannelSettingModesWithParamOnSet')
            ->willReturn(['k', 'l']);
        $this->modeSupport
            ->expects(self::once())
            ->method('hasPermanentChannelMode')
            ->willReturn(true);

        $this->channelServiceActions
            ->expects(self::once())
            ->method('setChannelModes')
            ->with('#test', '-ms', []);

        $this->burstCompletePort->expects(self::never())->method('isComplete');

        $event = new ChannelModesChangedEvent($channel, '+ntms', []);
        $this->subscriber->onChannelModesChanged($event);
    }

    #[Test]
    public function enforceMlockForChannelEmptyMlock(): void
    {
        $channel = new Channel(new ChannelName('#test'));
        $channel->updateModes('+ntrs');

        $registered = $this->createStub(RegisteredChannel::class);
        $registered->method('isMlockActive')->willReturn(true);
        $registered->method('getMlock')->willReturn('');

        $view = new ChannelView(name: '#test', modes: '+ntrs', topic: null, memberCount: 5);

        $this->burstCompletePort
            ->expects(self::atLeastOnce())
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
            ->with('#test')
            ->willReturn($view);

        $this->modeSupportProvider
            ->expects(self::atLeastOnce())
            ->method('getSupport')
            ->willReturn($this->modeSupport);

        $this->modeSupport
            ->expects(self::atLeastOnce())
            ->method('getChannelSettingModesUnsetWithoutParam')
            ->willReturn(['s', 'm', 'i', 'n', 't', 'r']);
        $this->modeSupport
            ->expects(self::atLeastOnce())
            ->method('getChannelSettingModesUnsetWithParam')
            ->willReturn(['k']);
        $this->modeSupport
            ->expects(self::atLeastOnce())
            ->method('getChannelSettingModesWithParamOnSet')
            ->willReturn(['k', 'l']);
        $this->modeSupport
            ->expects(self::atLeastOnce())
            ->method('hasChannelRegisteredMode')
            ->willReturn(true);
        $this->modeSupport
            ->expects(self::atLeastOnce())
            ->method('hasPermanentChannelMode')
            ->willReturn(true);

        $this->channelServiceActions
            ->expects(self::once())
            ->method('setChannelModes')
            ->with('#test', '-nts', []);

        $event = new ChannelSyncedEvent($channel, channelSetupApplicable: true);
        $this->subscriber->onChannelSynced($event);
    }

    #[Test]
    public function enforceMlockForChannelWithParameterModes(): void
    {
        $channel = new Channel(new ChannelName('#test'));
        $channel->updateModes('+ntkls');

        $registered = $this->createStub(RegisteredChannel::class);
        $registered->method('isMlockActive')->willReturn(true);
        $registered->method('getMlock')->willReturn('nt');
        $registered->method('getMlockParam')->willReturn(null);

        $view = new ChannelView(
            name: '#test',
            modes: '+ntkls',
            topic: null,
            memberCount: 5,
            modeParams: ['k' => 'secretkey', 'l' => '100'],
        );

        $this->burstCompletePort
            ->expects(self::atLeastOnce())
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
            ->with('#test')
            ->willReturn($view);

        $this->modeSupportProvider
            ->expects(self::atLeastOnce())
            ->method('getSupport')
            ->willReturn($this->modeSupport);

        $this->modeSupport
            ->expects(self::atLeastOnce())
            ->method('getChannelSettingModesUnsetWithoutParam')
            ->willReturn(['s', 'm', 'i', 'n', 't']);
        $this->modeSupport
            ->expects(self::atLeastOnce())
            ->method('getChannelSettingModesUnsetWithParam')
            ->willReturn(['k', 'l']);
        $this->modeSupport
            ->expects(self::atLeastOnce())
            ->method('getChannelSettingModesWithParamOnSet')
            ->willReturn(['k', 'l']);

        $this->channelServiceActions
            ->expects(self::once())
            ->method('setChannelModes')
            ->with('#test', '-kls', ['secretkey', '100']);

        $event = new ChannelSyncedEvent($channel, channelSetupApplicable: true);
        $this->subscriber->onChannelSynced($event);
    }

    #[Test]
    public function enforceMlockForChannelAddingModesWithParams(): void
    {
        $channel = new Channel(new ChannelName('#test'));
        $channel->updateModes('+nt');

        $registered = $this->createStub(RegisteredChannel::class);
        $registered->method('isMlockActive')->willReturn(true);
        $registered->method('getMlock')->willReturn('ntkl');
        $registered->method('getMlockParam')->willReturnMap([
            ['k', 'mypasskey'],
            ['l', '50'],
        ]);

        $view = new ChannelView(name: '#test', modes: '+nt', topic: null, memberCount: 5);

        $this->burstCompletePort
            ->expects(self::atLeastOnce())
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
            ->with('#test')
            ->willReturn($view);

        $this->modeSupportProvider
            ->expects(self::atLeastOnce())
            ->method('getSupport')
            ->willReturn($this->modeSupport);

        $this->modeSupport
            ->expects(self::atLeastOnce())
            ->method('getChannelSettingModesUnsetWithoutParam')
            ->willReturn(['s', 'm', 'i', 'n', 't']);
        $this->modeSupport
            ->expects(self::atLeastOnce())
            ->method('getChannelSettingModesUnsetWithParam')
            ->willReturn(['k', 'l']);
        $this->modeSupport
            ->expects(self::atLeastOnce())
            ->method('getChannelSettingModesWithParamOnSet')
            ->willReturn(['k', 'l']);

        $this->channelServiceActions
            ->expects(self::once())
            ->method('setChannelModes')
            ->with('#test', '+kl', ['mypasskey', '50']);

        $event = new ChannelSyncedEvent($channel, channelSetupApplicable: true);
        $this->subscriber->onChannelSynced($event);
    }

    #[Test]
    public function onChannelSyncedWhenChannelLookupNull(): void
    {
        $channel = new Channel(new ChannelName('#test'));

        $registered = $this->createStub(RegisteredChannel::class);
        $registered->method('isMlockActive')->willReturn(true);
        $registered->method('getMlock')->willReturn('nt');

        $this->burstCompletePort
            ->expects(self::atLeastOnce())
            ->method('isComplete')
            ->willReturn(true);

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
            ->expects(self::never())
            ->method('setChannelModes');
        $this->modeSupportProvider->expects(self::never())->method('getSupport');
        $this->modeSupport->expects(self::never())->method('getChannelSettingModesUnsetWithoutParam');

        $event = new ChannelSyncedEvent($channel, channelSetupApplicable: true);
        $this->subscriber->onChannelSynced($event);
    }

    #[Test]
    public function parseMlockLettersComplexString(): void
    {
        $channel = new Channel(new ChannelName('#test'));
        $channel->updateModes('+nt');

        $registered = $this->createStub(RegisteredChannel::class);
        $registered->method('isMlockActive')->willReturn(true);
        $registered->method('getMlock')->willReturn('nt');
        $registered->method('getMlockParam')->willReturn(null);

        $view = new ChannelView(name: '#test', modes: '+ntms', topic: null, memberCount: 5);

        $this->burstCompletePort
            ->expects(self::atLeastOnce())
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
            ->with('#test')
            ->willReturn($view);

        $this->modeSupportProvider
            ->expects(self::atLeastOnce())
            ->method('getSupport')
            ->willReturn($this->modeSupport);

        $this->modeSupport
            ->expects(self::atLeastOnce())
            ->method('getChannelSettingModesUnsetWithoutParam')
            ->willReturn(['s', 'm', 'i', 'n', 't']);
        $this->modeSupport
            ->expects(self::atLeastOnce())
            ->method('getChannelSettingModesUnsetWithParam')
            ->willReturn(['k', 'l']);
        $this->modeSupport
            ->expects(self::atLeastOnce())
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
    public function onSyncCompleteSkipsChannelNotFoundInLookup(): void
    {
        $registered = $this->createStub(RegisteredChannel::class);
        $registered->method('isMlockActive')->willReturn(true);
        $registered->method('getMlock')->willReturn('nt');
        $registered->method('getName')->willReturn('#channel1');

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
            ->method('setChannelModes');
        $this->modeSupportProvider->expects(self::never())->method('getSupport');
        $this->burstCompletePort->expects(self::never())->method('isComplete');
        $this->modeSupport->expects(self::never())->method('getChannelSettingModesUnsetWithoutParam');

        $connection = $this->createStub(\App\Domain\IRC\Connection\ConnectionInterface::class);
        $event = new NetworkSyncCompleteEvent($connection, '001');
        $this->subscriber->onSyncComplete($event);
    }

    #[Test]
    public function onSyncCompleteSkipsWhenMlockNotActive(): void
    {
        $registered = $this->createStub(RegisteredChannel::class);
        $registered->method('isMlockActive')->willReturn(false);
        $registered->method('getName')->willReturn('#channel1');

        $view = new ChannelView(name: '#channel1', modes: '+nt', topic: null, memberCount: 5);

        $this->channelRepository
            ->expects(self::once())
            ->method('listAll')
            ->willReturn([$registered]);

        $this->channelLookup
            ->expects(self::never())
            ->method('findByChannelName');

        $this->channelServiceActions
            ->expects(self::never())
            ->method('setChannelModes');
        $this->modeSupportProvider->expects(self::never())->method('getSupport');
        $this->burstCompletePort->expects(self::never())->method('isComplete');
        $this->modeSupport->expects(self::never())->method('getChannelSettingModesUnsetWithoutParam');

        $connection = $this->createStub(\App\Domain\IRC\Connection\ConnectionInterface::class);
        $event = new NetworkSyncCompleteEvent($connection, '001');
        $this->subscriber->onSyncComplete($event);
    }

    #[Test]
    public function enforceMlockDoesNothingWhenModesAlreadyMatchMlock(): void
    {
        $channel = new Channel(new ChannelName('#test'));
        $channel->updateModes('+nt');

        $registered = $this->createStub(RegisteredChannel::class);
        $registered->method('isMlockActive')->willReturn(true);
        $registered->method('getMlock')->willReturn('nt');
        $registered->method('getMlockParam')->willReturn(null);

        $view = new ChannelView(name: '#test', modes: '+nt', topic: null, memberCount: 5);

        $this->burstCompletePort
            ->expects(self::atLeastOnce())
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
            ->with('#test')
            ->willReturn($view);

        $this->modeSupportProvider
            ->expects(self::atLeastOnce())
            ->method('getSupport')
            ->willReturn($this->modeSupport);

        $this->modeSupport
            ->expects(self::atLeastOnce())
            ->method('getChannelSettingModesUnsetWithoutParam')
            ->willReturn(['s', 'm', 'i', 'n', 't']);
        $this->modeSupport
            ->expects(self::atLeastOnce())
            ->method('getChannelSettingModesUnsetWithParam')
            ->willReturn(['k']);
        $this->modeSupport
            ->expects(self::atLeastOnce())
            ->method('getChannelSettingModesWithParamOnSet')
            ->willReturn(['k', 'l']);

        $this->channelServiceActions
            ->expects(self::never())
            ->method('setChannelModes');

        $event = new ChannelSyncedEvent($channel, channelSetupApplicable: true);
        $this->subscriber->onChannelSynced($event);
    }

    #[Test]
    public function onMlockUpdatedDoesNothingWhenChannelNotRegistered(): void
    {
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
        $this->burstCompletePort->expects(self::never())->method('isComplete');
        $this->modeSupport->expects(self::never())->method('getChannelSettingModesUnsetWithoutParam');

        $event = new ChannelMlockUpdatedEvent(channelName: '#test');
        $this->subscriber->onMlockUpdated($event);
    }

    #[Test]
    public function onMlockUpdatedDoesNothingWhenMlockNotActive(): void
    {
        $registered = $this->createStub(RegisteredChannel::class);
        $registered->method('isMlockActive')->willReturn(false);

        $this->channelRepository
            ->expects(self::once())
            ->method('findByChannelName')
            ->with('#test')
            ->willReturn($registered);

        $this->channelServiceActions
            ->expects(self::never())
            ->method('setChannelModes');
        $this->channelLookup->expects(self::never())->method('findByChannelName');
        $this->modeSupportProvider->expects(self::never())->method('getSupport');
        $this->burstCompletePort->expects(self::never())->method('isComplete');
        $this->modeSupport->expects(self::never())->method('getChannelSettingModesUnsetWithoutParam');

        $event = new ChannelMlockUpdatedEvent(channelName: '#test');
        $this->subscriber->onMlockUpdated($event);
    }

    #[Test]
    public function onMlockUpdatedDoesNothingWhenChannelLookupNull(): void
    {
        $registered = $this->createStub(RegisteredChannel::class);
        $registered->method('isMlockActive')->willReturn(true);
        $registered->method('getMlock')->willReturn('nt');

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
            ->expects(self::never())
            ->method('setChannelModes');
        $this->modeSupportProvider->expects(self::never())->method('getSupport');
        $this->burstCompletePort->expects(self::never())->method('isComplete');
        $this->modeSupport->expects(self::never())->method('getChannelSettingModesUnsetWithoutParam');

        $event = new ChannelMlockUpdatedEvent(channelName: '#test');
        $this->subscriber->onMlockUpdated($event);
    }

    #[Test]
    public function onSyncCompleteWithEmptyListAll(): void
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
            ->method('setChannelModes');
        $this->modeSupportProvider->expects(self::never())->method('getSupport');
        $this->burstCompletePort->expects(self::never())->method('isComplete');
        $this->modeSupport->expects(self::never())->method('getChannelSettingModesUnsetWithoutParam');

        $connection = $this->createStub(\App\Domain\IRC\Connection\ConnectionInterface::class);
        $event = new NetworkSyncCompleteEvent($connection, '001');
        $this->subscriber->onSyncComplete($event);
    }

    #[Test]
    public function enforceMlockPreservesRegisteredMode(): void
    {
        $channel = new Channel(new ChannelName('#test'));
        $channel->updateModes('+ntr');

        $registered = $this->createStub(RegisteredChannel::class);
        $registered->method('isMlockActive')->willReturn(true);
        $registered->method('getMlock')->willReturn('nt');
        $registered->method('getMlockParam')->willReturn(null);

        $view = new ChannelView(name: '#test', modes: '+ntr', topic: null, memberCount: 5);

        $this->burstCompletePort
            ->expects(self::atLeastOnce())
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
            ->with('#test')
            ->willReturn($view);

        $this->modeSupportProvider
            ->expects(self::atLeastOnce())
            ->method('getSupport')
            ->willReturn($this->modeSupport);

        $this->modeSupport
            ->expects(self::atLeastOnce())
            ->method('getChannelSettingModesUnsetWithoutParam')
            ->willReturn(['s', 'm', 'i', 'n', 't', 'r']);
        $this->modeSupport
            ->expects(self::atLeastOnce())
            ->method('getChannelSettingModesUnsetWithParam')
            ->willReturn(['k']);
        $this->modeSupport
            ->expects(self::atLeastOnce())
            ->method('getChannelSettingModesWithParamOnSet')
            ->willReturn(['k', 'l']);
        $this->modeSupport
            ->expects(self::atLeastOnce())
            ->method('hasChannelRegisteredMode')
            ->willReturn(true);
        $this->modeSupport
            ->expects(self::atLeastOnce())
            ->method('hasPermanentChannelMode')
            ->willReturn(true);

        $this->channelServiceActions
            ->expects(self::never())
            ->method('setChannelModes');

        $event = new ChannelSyncedEvent($channel, channelSetupApplicable: true);
        $this->subscriber->onChannelSynced($event);
    }

    #[Test]
    public function enforceMlockPreservesPermanentModeWhenSupported(): void
    {
        $channel = new Channel(new ChannelName('#test'));
        $channel->updateModes('+ntP');

        $registered = $this->createStub(RegisteredChannel::class);
        $registered->method('isMlockActive')->willReturn(true);
        $registered->method('getMlock')->willReturn('nt');
        $registered->method('getMlockParam')->willReturn(null);

        $view = new ChannelView(name: '#test', modes: '+ntP', topic: null, memberCount: 5);

        $this->burstCompletePort
            ->expects(self::atLeastOnce())
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
            ->with('#test')
            ->willReturn($view);

        $this->modeSupportProvider
            ->expects(self::atLeastOnce())
            ->method('getSupport')
            ->willReturn($this->modeSupport);

        $this->modeSupport
            ->expects(self::atLeastOnce())
            ->method('getChannelSettingModesUnsetWithoutParam')
            ->willReturn(['s', 'm', 'i', 'n', 't', 'P']);
        $this->modeSupport
            ->expects(self::atLeastOnce())
            ->method('getChannelSettingModesUnsetWithParam')
            ->willReturn(['k']);
        $this->modeSupport
            ->expects(self::atLeastOnce())
            ->method('getChannelSettingModesWithParamOnSet')
            ->willReturn(['k', 'l']);
        $this->modeSupport
            ->expects(self::atLeastOnce())
            ->method('hasPermanentChannelMode')
            ->willReturn(true);

        $this->channelServiceActions
            ->expects(self::never())
            ->method('setChannelModes');

        $event = new ChannelSyncedEvent($channel, channelSetupApplicable: true);
        $this->subscriber->onChannelSynced($event);
    }

    #[Test]
    public function enforceMlockRemovesPermanentModeWhenNotSupported(): void
    {
        $channel = new Channel(new ChannelName('#test'));
        $channel->updateModes('+ntP');

        $registered = $this->createStub(RegisteredChannel::class);
        $registered->method('isMlockActive')->willReturn(true);
        $registered->method('getMlock')->willReturn('nt');
        $registered->method('getMlockParam')->willReturn(null);

        $view = new ChannelView(name: '#test', modes: '+ntP', topic: null, memberCount: 5);

        $this->burstCompletePort
            ->expects(self::atLeastOnce())
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
            ->with('#test')
            ->willReturn($view);

        $this->modeSupportProvider
            ->expects(self::atLeastOnce())
            ->method('getSupport')
            ->willReturn($this->modeSupport);

        $this->modeSupport
            ->expects(self::atLeastOnce())
            ->method('getChannelSettingModesUnsetWithoutParam')
            ->willReturn(['s', 'm', 'i', 'n', 't', 'P']);
        $this->modeSupport
            ->expects(self::atLeastOnce())
            ->method('getChannelSettingModesUnsetWithParam')
            ->willReturn(['k']);
        $this->modeSupport
            ->expects(self::atLeastOnce())
            ->method('getChannelSettingModesWithParamOnSet')
            ->willReturn(['k', 'l']);
        $this->modeSupport
            ->expects(self::atLeastOnce())
            ->method('hasPermanentChannelMode')
            ->willReturn(false);

        $this->channelServiceActions
            ->expects(self::once())
            ->method('setChannelModes')
            ->with('#test', '-P', []);

        $event = new ChannelSyncedEvent($channel, channelSetupApplicable: true);
        $this->subscriber->onChannelSynced($event);
    }

    #[Test]
    public function enforceMlockRemovesRegisteredModeWhenNotSupported(): void
    {
        $channel = new Channel(new ChannelName('#test'));
        $channel->updateModes('+ntr');

        $registered = $this->createStub(RegisteredChannel::class);
        $registered->method('isMlockActive')->willReturn(true);
        $registered->method('getMlock')->willReturn('nt');
        $registered->method('getMlockParam')->willReturn(null);

        $view = new ChannelView(name: '#test', modes: '+ntr', topic: null, memberCount: 5);

        $this->burstCompletePort
            ->expects(self::atLeastOnce())
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
            ->with('#test')
            ->willReturn($view);

        $this->modeSupportProvider
            ->expects(self::atLeastOnce())
            ->method('getSupport')
            ->willReturn($this->modeSupport);

        $this->modeSupport
            ->expects(self::atLeastOnce())
            ->method('getChannelSettingModesUnsetWithoutParam')
            ->willReturn(['s', 'm', 'i', 'n', 't', 'r']);
        $this->modeSupport
            ->expects(self::atLeastOnce())
            ->method('getChannelSettingModesUnsetWithParam')
            ->willReturn(['k']);
        $this->modeSupport
            ->expects(self::atLeastOnce())
            ->method('getChannelSettingModesWithParamOnSet')
            ->willReturn(['k', 'l']);
        $this->modeSupport
            ->expects(self::atLeastOnce())
            ->method('hasChannelRegisteredMode')
            ->willReturn(false);
        $this->modeSupport
            ->expects(self::atLeastOnce())
            ->method('hasPermanentChannelMode')
            ->willReturn(true);

        $this->channelServiceActions
            ->expects(self::once())
            ->method('setChannelModes')
            ->with('#test', '-r', []);

        $event = new ChannelSyncedEvent($channel, channelSetupApplicable: true);
        $this->subscriber->onChannelSynced($event);
    }

    #[Test]
    public function onChannelModesChangedDoesNothingWhenModesAlreadyMatch(): void
    {
        $channel = new Channel(new ChannelName('#test'));
        $channel->updateModes('+nt');

        $registered = $this->createStub(RegisteredChannel::class);
        $registered->method('isMlockActive')->willReturn(true);
        $registered->method('getMlock')->willReturn('nt');

        $view = new ChannelView(name: '#test', modes: '+nt', topic: null, memberCount: 5);

        $this->channelRepository
            ->expects(self::once())
            ->method('findByChannelName')
            ->with('#test')
            ->willReturn($registered);

        $this->channelLookup
            ->expects(self::once())
            ->method('findByChannelName')
            ->with('#test')
            ->willReturn($view);

        $this->modeSupportProvider
            ->expects(self::once())
            ->method('getSupport')
            ->willReturn($this->modeSupport);

        $this->modeSupport
            ->expects(self::once())
            ->method('getChannelSettingModesUnsetWithoutParam')
            ->willReturn(['s', 'm', 'i', 'n', 't']);
        $this->modeSupport
            ->expects(self::once())
            ->method('getChannelSettingModesUnsetWithParam')
            ->willReturn(['k']);
        $this->modeSupport
            ->expects(self::once())
            ->method('getChannelSettingModesWithParamOnSet')
            ->willReturn(['k', 'l']);
        $this->modeSupport
            ->expects(self::once())
            ->method('hasPermanentChannelMode')
            ->willReturn(true);

        $this->channelServiceActions
            ->expects(self::never())
            ->method('setChannelModes');
        $this->burstCompletePort->expects(self::never())->method('isComplete');

        $event = new ChannelModesChangedEvent($channel, '+nt', []);
        $this->subscriber->onChannelModesChanged($event);
    }

    #[Test]
    public function enforcesMlockPreservingCaseForUppercaseAndLowercaseModes(): void
    {
        $channel = new Channel(new ChannelName('#test'));
        $channel->updateModes('+ntrR');

        $registered = $this->createStub(RegisteredChannel::class);
        $registered->method('isMlockActive')->willReturn(true);
        $registered->method('getMlock')->willReturn('nt');
        $registered->method('getMlockParam')->willReturn(null);

        $view = new ChannelView(name: '#test', modes: '+ntrR', topic: null, memberCount: 5);

        $this->burstCompletePort
            ->expects(self::atLeastOnce())
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
            ->with('#test')
            ->willReturn($view);

        $this->modeSupportProvider
            ->expects(self::atLeastOnce())
            ->method('getSupport')
            ->willReturn($this->modeSupport);

        $this->modeSupport
            ->expects(self::atLeastOnce())
            ->method('getChannelSettingModesUnsetWithoutParam')
            ->willReturn(['s', 'm', 'i', 'n', 't', 'r', 'R']);
        $this->modeSupport
            ->expects(self::atLeastOnce())
            ->method('getChannelSettingModesUnsetWithParam')
            ->willReturn(['k']);
        $this->modeSupport
            ->expects(self::atLeastOnce())
            ->method('getChannelSettingModesWithParamOnSet')
            ->willReturn(['k', 'l']);
        $this->modeSupport
            ->expects(self::atLeastOnce())
            ->method('hasChannelRegisteredMode')
            ->willReturn(true);
        $this->modeSupport
            ->expects(self::atLeastOnce())
            ->method('hasPermanentChannelMode')
            ->willReturn(true);

        $this->channelServiceActions
            ->expects(self::once())
            ->method('setChannelModes')
            ->with('#test', '-R', []);

        $event = new ChannelSyncedEvent($channel, channelSetupApplicable: true);
        $this->subscriber->onChannelSynced($event);
    }

    #[Test]
    public function parseMlockLettersWithPlusPrefix(): void
    {
        $channel = new Channel(new ChannelName('#test'));
        $channel->updateModes('+ntms');

        $registered = $this->createStub(RegisteredChannel::class);
        $registered->method('isMlockActive')->willReturn(true);
        $registered->method('getMlock')->willReturn('+nt');
        $registered->method('getMlockParam')->willReturn(null);

        $view = new ChannelView(name: '#test', modes: '+ntms', topic: null, memberCount: 5);

        $this->burstCompletePort
            ->expects(self::atLeastOnce())
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
            ->with('#test')
            ->willReturn($view);

        $this->modeSupportProvider
            ->expects(self::atLeastOnce())
            ->method('getSupport')
            ->willReturn($this->modeSupport);

        $this->modeSupport
            ->expects(self::atLeastOnce())
            ->method('getChannelSettingModesUnsetWithoutParam')
            ->willReturn(['s', 'm', 'i', 'n', 't']);
        $this->modeSupport
            ->expects(self::atLeastOnce())
            ->method('getChannelSettingModesUnsetWithParam')
            ->willReturn(['k']);
        $this->modeSupport
            ->expects(self::atLeastOnce())
            ->method('getChannelSettingModesWithParamOnSet')
            ->willReturn(['k', 'l']);

        $this->channelServiceActions
            ->expects(self::once())
            ->method('setChannelModes')
            ->with('#test', '-ms', []);

        $event = new ChannelSyncedEvent($channel, channelSetupApplicable: true);
        $this->subscriber->onChannelSynced($event);
    }
}
