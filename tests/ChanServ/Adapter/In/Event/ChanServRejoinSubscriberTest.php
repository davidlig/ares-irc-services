<?php

declare(strict_types=1);

namespace App\Tests\ChanServ\Adapter\In\Event;

use App\ChanServ\Adapter\In\Event\ChanServRejoinSubscriber;
use App\ChanServ\Adapter\Out\Network\IrcChannelRegistrationNetworkActions;
use App\ChanServ\Application\Port\Out\RegisteredChannelRepositoryInterface;
use App\ChanServ\Application\Service\ChannelRegistrationService;
use App\ChanServ\Domain\Entity\RegisteredChannel;
use App\Irc\Application\Port\In\ActiveChannelModeSupportProviderInterface;
use App\Irc\Application\Port\In\ChannelLookupPort;
use App\Irc\Application\Port\In\ChannelModeSupportInterface;
use App\Irc\Application\Port\In\ChannelServiceActionsPort;
use App\Irc\Application\Port\In\ChannelView;
use App\Irc\Application\PublishedEvent\ChannelSynchronizedEvent;
use App\Irc\Application\PublishedEvent\NetworkSynchronizationCompletedEvent;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

#[CoversClass(ChanServRejoinSubscriber::class)]
#[CoversClass(ChannelRegistrationService::class)]
#[CoversClass(IrcChannelRegistrationNetworkActions::class)]
final class ChanServRejoinSubscriberTest extends TestCase
{
    private MockObject&RegisteredChannelRepositoryInterface $channelRepository;

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

        $this->subscriber = $this->createSubscriber(
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
        $this->channelRepository->expects(self::never())->method('iterateAll');
        $this->channelLookup->expects(self::never())->method('findByChannelName');
        $this->modeSupportProvider->expects(self::never())->method('getSupport');
        $this->modeSupport->expects(self::never())->method('getChannelRegisteredModeLetter');
        $this->channelServiceActions->expects(self::never())->method('setChannelModes');
        $this->logger->expects(self::never())->method('warning');

        self::assertSame(
            [
                NetworkSynchronizationCompletedEvent::class => [
                    ['onSyncCompleteReconcileRegisteredMode', 10],
                    ['onSyncCompleteReconcilePermanentMode', 9],
                ],
                ChannelSynchronizedEvent::class => ['onChannelSyncedSetRegistered', 10],
            ],
            ChanServRejoinSubscriber::getSubscribedEvents(),
        );
    }

    #[Test]
    public function setsChannelRegisteredOnChannelSynced(): void
    {
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
            ->method('getChannelRegisteredModeLetter')
            ->willReturn('r');

        $view = new ChannelView('#test', '+n', null, 0);
        $this->channelLookup
            ->expects(self::once())
            ->method('findByChannelName')
            ->with('#test')
            ->willReturn($view);

        $this->channelServiceActions
            ->expects(self::once())
            ->method('setChannelModes')
            ->with('#test', '+r', []);
        $this->logger->expects(self::never())->method('warning');

        $event = new ChannelSynchronizedEvent('#test', channelSetupApplicable: true);
        $this->subscriber->onChannelSyncedSetRegistered($event);
    }

    #[Test]
    public function doesNotSetRegisteredWhenChannelNotRegistered(): void
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
        $this->modeSupport->expects(self::never())->method('getChannelRegisteredModeLetter');
        $this->logger->expects(self::never())->method('warning');

        $event = new ChannelSynchronizedEvent('#test', channelSetupApplicable: true);
        $this->subscriber->onChannelSyncedSetRegistered($event);
    }

    #[Test]
    public function doesNotSetRegisteredWhenNoChannelRegisteredMode(): void
    {
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
            ->method('getChannelRegisteredModeLetter')
            ->willReturn(null);

        $this->channelServiceActions
            ->expects(self::never())
            ->method('setChannelModes');
        $this->channelLookup->expects(self::never())->method('findByChannelName');
        $this->logger->expects(self::never())->method('warning');

        $event = new ChannelSynchronizedEvent('#test', channelSetupApplicable: true);
        $this->subscriber->onChannelSyncedSetRegistered($event);
    }

    #[Test]
    public function setsRegisteredEvenWhenChannelSetupNotApplicable(): void
    {
        $registered = $this->createStub(RegisteredChannel::class);
        $registered->method('isSuspended')->willReturn(false);
        $registered->method('isForbidden')->willReturn(false);

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
            ->method('getChannelRegisteredModeLetter')
            ->willReturn('r');

        $view = new ChannelView(name: '#test', modes: '+nt', topic: null, memberCount: 1);

        $this->channelLookup
            ->expects(self::once())
            ->method('findByChannelName')
            ->with('#test')
            ->willReturn($view);

        $this->channelServiceActions
            ->expects(self::once())
            ->method('setChannelModes')
            ->with('#test', '+r', []);

        $this->logger
            ->expects(self::once())
            ->method('debug');

        $event = new ChannelSynchronizedEvent('#test', channelSetupApplicable: false);
        $this->subscriber->onChannelSyncedSetRegistered($event);
    }

    #[Test]
    public function doesNotSetRegisteredWhenAlreadyHasMode(): void
    {
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
            ->method('getChannelRegisteredModeLetter')
            ->willReturn('r');

        $view = new ChannelView('#test', '+ntr', null, 0);
        $this->channelLookup
            ->expects(self::once())
            ->method('findByChannelName')
            ->with('#test')
            ->willReturn($view);

        $this->channelServiceActions
            ->expects(self::never())
            ->method('setChannelModes');
        $this->logger->expects(self::never())->method('warning');

        $event = new ChannelSynchronizedEvent('#test', channelSetupApplicable: true);
        $this->subscriber->onChannelSyncedSetRegistered($event);
    }

    #[Test]
    public function doesNotSetRegisteredWhenChannelNotOnNetwork(): void
    {
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
            ->method('getChannelRegisteredModeLetter')
            ->willReturn('r');

        $this->channelLookup
            ->expects(self::once())
            ->method('findByChannelName')
            ->with('#test')
            ->willReturn(null);

        $this->channelServiceActions
            ->expects(self::never())
            ->method('setChannelModes');
        $this->logger->expects(self::never())->method('warning');

        $event = new ChannelSynchronizedEvent('#test', channelSetupApplicable: true);
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
            ->method('iterateAll')
            ->willReturn([$registered1, $registered2]);

        $this->modeSupportProvider
            ->expects(self::exactly(2))
            ->method('getSupport')
            ->willReturn($this->modeSupport);

        $this->modeSupport
            ->expects(self::exactly(2))
            ->method('getChannelRegisteredModeLetter')
            ->willReturn('r');

        $this->channelLookup
            ->expects(self::exactly(2))
            ->method('findByChannelName')
            ->willReturnMap([
                ['#channel1', $view1],
                ['#channel2', $view2],
            ]);

        $this->channelLookup
            ->expects(self::once())
            ->method('iterateModeSnapshots')
            ->willReturn([$view1, $view2]);

        $this->channelServiceActions
            ->expects(self::exactly(2))
            ->method('setChannelModes');
        $this->logger->expects(self::never())->method('warning');
        $event = new NetworkSynchronizationCompletedEvent('001');
        $this->subscriber->onSyncCompleteReconcileRegisteredMode($event);
    }

    #[Test]
    public function doesNothingWhenNoRegisteredChannels(): void
    {
        $this->modeSupportProvider
            ->expects(self::exactly(2))
            ->method('getSupport')
            ->willReturn($this->modeSupport);

        $this->modeSupport
            ->expects(self::exactly(2))
            ->method('getChannelRegisteredModeLetter')
            ->willReturn('r');

        $this->channelRepository
            ->expects(self::once())
            ->method('iterateAll')
            ->willReturn([]);

        $this->channelLookup->expects(self::never())->method('findByChannelName');
        $this->channelServiceActions->expects(self::never())->method('setChannelModes');
        $this->logger->expects(self::never())->method('warning');
        $event = new NetworkSynchronizationCompletedEvent('001');
        $this->subscriber->onSyncCompleteReconcileRegisteredMode($event);
    }

    #[Test]
    public function onSyncCompleteSkipsMissingChannel(): void
    {
        $registered = $this->createStub(RegisteredChannel::class);
        $registered->method('getName')->willReturn('#channelnotonnetwork');

        $this->modeSupportProvider
            ->expects(self::exactly(2))
            ->method('getSupport')
            ->willReturn($this->modeSupport);

        $this->modeSupport
            ->expects(self::exactly(2))
            ->method('getChannelRegisteredModeLetter')
            ->willReturn('r');

        $this->channelRepository
            ->expects(self::once())
            ->method('iterateAll')
            ->willReturn([$registered]);

        $this->channelLookup
            ->expects(self::once())
            ->method('findByChannelName')
            ->with('#channelnotonnetwork')
            ->willReturn(null);

        $this->channelLookup
            ->expects(self::once())
            ->method('iterateModeSnapshots')
            ->willReturn([]);

        $this->channelServiceActions
            ->expects(self::never())
            ->method('setChannelModes');
        $this->logger->expects(self::never())->method('warning');
        $event = new NetworkSynchronizationCompletedEvent('001');
        $this->subscriber->onSyncCompleteReconcileRegisteredMode($event);
    }

    #[Test]
    public function onSyncCompleteSkipsWhenNoRegisteredMode(): void
    {
        $this->modeSupportProvider
            ->expects(self::once())
            ->method('getSupport')
            ->willReturn($this->modeSupport);

        $this->modeSupport
            ->expects(self::once())
            ->method('getChannelRegisteredModeLetter')
            ->willReturn(null);

        $this->channelRepository->expects(self::never())->method('iterateAll');
        $this->channelLookup->expects(self::never())->method('findByChannelName');
        $this->channelServiceActions->expects(self::never())->method('setChannelModes');
        $this->logger->expects(self::never())->method('warning');
        $event = new NetworkSynchronizationCompletedEvent('001');
        $this->subscriber->onSyncCompleteReconcileRegisteredMode($event);
    }

    #[Test]
    public function registrationNetworkActionsSkipReconciliationWhenRegisteredModeIsNotSupported(): void
    {
        $this->channelRepository->expects(self::never())->method('iterateAll');
        $this->modeSupportProvider
            ->expects(self::once())
            ->method('getSupport')
            ->willReturn($this->modeSupport);
        $this->modeSupport
            ->expects(self::once())
            ->method('getChannelRegisteredModeLetter')
            ->willReturn(null);
        $this->channelLookup->expects(self::never())->method('findByChannelName');
        $this->channelLookup->expects(self::never())->method('iterateModeSnapshots');
        $this->channelServiceActions->expects(self::never())->method('setChannelModes');
        $this->logger->expects(self::never())->method('debug');

        $networkActions = new IrcChannelRegistrationNetworkActions(
            $this->modeSupportProvider,
            $this->channelLookup,
            $this->channelServiceActions,
            $this->logger,
        );

        $networkActions->reconcileRegisteredMode([], []);
    }

    #[Test]
    public function onSyncCompleteReconcileRegisteredModeRemovesRFromUnregisteredChannels(): void
    {
        $registered = $this->createStub(RegisteredChannel::class);
        $registered->method('getName')->willReturn('#registered');

        $this->channelRepository
            ->expects(self::once())
            ->method('iterateAll')
            ->willReturn([$registered]);

        $this->modeSupportProvider
            ->expects(self::exactly(2))
            ->method('getSupport')
            ->willReturn($this->modeSupport);

        $this->modeSupport
            ->expects(self::exactly(2))
            ->method('getChannelRegisteredModeLetter')
            ->willReturn('r');

        $registeredView = new ChannelView('#registered', '+ntr', null, 1);
        $unregisteredView = new ChannelView('#unregistered', '+ntr', null, 1);

        $this->channelLookup
            ->expects(self::once())
            ->method('findByChannelName')
            ->with('#registered')
            ->willReturn($registeredView);

        $this->channelLookup
            ->expects(self::once())
            ->method('iterateModeSnapshots')
            ->willReturn([$registeredView, $unregisteredView]);

        $this->channelServiceActions
            ->expects(self::once())
            ->method('setChannelModes')
            ->with('#unregistered', '-r', []);

        $this->logger
            ->expects(self::once())
            ->method('debug');
        $event = new NetworkSynchronizationCompletedEvent('001');
        $this->subscriber->onSyncCompleteReconcileRegisteredMode($event);
    }

    #[Test]
    public function onSyncCompleteReconcileRegisteredModeDoesNotSetRWhenAlreadyPresent(): void
    {
        $registered = $this->createStub(RegisteredChannel::class);
        $registered->method('getName')->willReturn('#test');

        $this->channelRepository
            ->expects(self::once())
            ->method('iterateAll')
            ->willReturn([$registered]);

        $this->modeSupportProvider
            ->expects(self::exactly(2))
            ->method('getSupport')
            ->willReturn($this->modeSupport);

        $this->modeSupport
            ->expects(self::exactly(2))
            ->method('getChannelRegisteredModeLetter')
            ->willReturn('r');

        $view = new ChannelView('#test', '+ntr', null, 1);

        $this->channelLookup
            ->expects(self::once())
            ->method('findByChannelName')
            ->with('#test')
            ->willReturn($view);

        $this->channelLookup
            ->expects(self::once())
            ->method('iterateModeSnapshots')
            ->willReturn([$view]);

        $this->channelServiceActions->expects(self::never())->method('setChannelModes');
        $this->logger->expects(self::never())->method('debug');
        $event = new NetworkSynchronizationCompletedEvent('001');
        $this->subscriber->onSyncCompleteReconcileRegisteredMode($event);
    }

    #[Test]
    public function onSyncCompleteReconcilePermanentModeDoesNothingWhenModeNotSupported(): void
    {
        $this->channelRepository->expects(self::never())->method('iterateAll');
        $this->modeSupportProvider
            ->expects(self::once())
            ->method('getSupport')
            ->willReturn($this->modeSupport);
        $this->modeSupport
            ->expects(self::once())
            ->method('getPermanentChannelModeLetter')
            ->willReturn(null);
        $this->channelLookup->expects(self::never())->method('iterateModeSnapshots');
        $this->channelServiceActions->expects(self::never())->method('setChannelModes');
        $this->logger->expects(self::never())->method('debug');
        $event = new NetworkSynchronizationCompletedEvent('001');
        $this->subscriber->onSyncCompleteReconcilePermanentMode($event);
    }

    #[Test]
    public function registrationNetworkActionsSkipReconciliationWhenPermanentModeIsNotSupported(): void
    {
        $this->channelRepository->expects(self::never())->method('iterateAll');
        $this->modeSupportProvider
            ->expects(self::once())
            ->method('getSupport')
            ->willReturn($this->modeSupport);
        $this->modeSupport
            ->expects(self::once())
            ->method('getPermanentChannelModeLetter')
            ->willReturn(null);
        $this->channelLookup->expects(self::never())->method('findByChannelName');
        $this->channelLookup->expects(self::never())->method('iterateModeSnapshots');
        $this->channelServiceActions->expects(self::never())->method('setChannelModes');
        $this->logger->expects(self::never())->method('debug');

        $networkActions = new IrcChannelRegistrationNetworkActions(
            $this->modeSupportProvider,
            $this->channelLookup,
            $this->channelServiceActions,
            $this->logger,
        );

        $networkActions->reconcilePermanentMode([], []);
    }

    #[Test]
    public function onSyncCompleteReconcilePermanentModeAddsPToRegisteredChannelsMissingIt(): void
    {
        $registered = $this->createStub(RegisteredChannel::class);
        $registered->method('getName')->willReturn('#test');

        $this->channelRepository
            ->expects(self::once())
            ->method('iterateAll')
            ->willReturn([$registered]);

        $this->modeSupportProvider
            ->expects(self::exactly(2))
            ->method('getSupport')
            ->willReturn($this->modeSupport);

        $this->modeSupport
            ->expects(self::exactly(2))
            ->method('getPermanentChannelModeLetter')
            ->willReturn('P');

        $view = new ChannelView('#test', '+nt', null, 1);

        $this->channelLookup
            ->expects(self::once())
            ->method('findByChannelName')
            ->with('#test')
            ->willReturn($view);

        $this->channelLookup
            ->expects(self::once())
            ->method('iterateModeSnapshots')
            ->willReturn([$view]);

        $this->channelServiceActions
            ->expects(self::once())
            ->method('setChannelModes')
            ->with('#test', '+P', []);

        $this->logger
            ->expects(self::once())
            ->method('debug');
        $event = new NetworkSynchronizationCompletedEvent('001');
        $this->subscriber->onSyncCompleteReconcilePermanentMode($event);
    }

    #[Test]
    public function onSyncCompleteReconcilePermanentModeDoesNotAddPWhenAlreadyPresent(): void
    {
        $registered = $this->createStub(RegisteredChannel::class);
        $registered->method('getName')->willReturn('#test');

        $this->channelRepository
            ->expects(self::once())
            ->method('iterateAll')
            ->willReturn([$registered]);

        $this->modeSupportProvider
            ->expects(self::exactly(2))
            ->method('getSupport')
            ->willReturn($this->modeSupport);

        $this->modeSupport
            ->expects(self::exactly(2))
            ->method('getPermanentChannelModeLetter')
            ->willReturn('P');

        $view = new ChannelView('#test', '+ntP', null, 1);

        $this->channelLookup
            ->expects(self::once())
            ->method('findByChannelName')
            ->with('#test')
            ->willReturn($view);

        $this->channelLookup
            ->expects(self::once())
            ->method('iterateModeSnapshots')
            ->willReturn([$view]);

        $this->channelServiceActions->expects(self::never())->method('setChannelModes');
        $this->logger->expects(self::never())->method('debug');
        $event = new NetworkSynchronizationCompletedEvent('001');
        $this->subscriber->onSyncCompleteReconcilePermanentMode($event);
    }

    #[Test]
    public function onSyncCompleteReconcilePermanentModeRemovesPFromUnregisteredChannels(): void
    {
        $registered = $this->createStub(RegisteredChannel::class);
        $registered->method('getName')->willReturn('#registered');

        $this->channelRepository
            ->expects(self::once())
            ->method('iterateAll')
            ->willReturn([$registered]);

        $this->modeSupportProvider
            ->expects(self::exactly(2))
            ->method('getSupport')
            ->willReturn($this->modeSupport);

        $this->modeSupport
            ->expects(self::exactly(2))
            ->method('getPermanentChannelModeLetter')
            ->willReturn('P');

        $registeredView = new ChannelView('#registered', '+ntP', null, 1);
        $unregisteredView = new ChannelView('#unregistered', '+ntP', null, 1);

        $this->channelLookup
            ->expects(self::once())
            ->method('findByChannelName')
            ->with('#registered')
            ->willReturn($registeredView);

        $this->channelLookup
            ->expects(self::once())
            ->method('iterateModeSnapshots')
            ->willReturn([$registeredView, $unregisteredView]);

        $this->channelServiceActions
            ->expects(self::once())
            ->method('setChannelModes')
            ->with('#unregistered', '-P', []);

        $this->logger
            ->expects(self::once())
            ->method('debug');
        $event = new NetworkSynchronizationCompletedEvent('001');
        $this->subscriber->onSyncCompleteReconcilePermanentMode($event);
    }

    #[Test]
    public function onSyncCompleteReconcilePermanentModeSkipsRegisteredChannelNotOnNetwork(): void
    {
        $registered = $this->createStub(RegisteredChannel::class);
        $registered->method('getName')->willReturn('#notonnetwork');

        $this->channelRepository
            ->expects(self::once())
            ->method('iterateAll')
            ->willReturn([$registered]);

        $this->modeSupportProvider
            ->expects(self::exactly(2))
            ->method('getSupport')
            ->willReturn($this->modeSupport);

        $this->modeSupport
            ->expects(self::exactly(2))
            ->method('getPermanentChannelModeLetter')
            ->willReturn('P');

        $this->channelLookup
            ->expects(self::once())
            ->method('findByChannelName')
            ->with('#notonnetwork')
            ->willReturn(null);

        $this->channelLookup
            ->expects(self::once())
            ->method('iterateModeSnapshots')
            ->willReturn([]);

        $this->channelServiceActions->expects(self::never())->method('setChannelModes');
        $this->logger->expects(self::never())->method('debug');
        $event = new NetworkSynchronizationCompletedEvent('001');
        $this->subscriber->onSyncCompleteReconcilePermanentMode($event);
    }

    #[Test]
    public function onChannelSyncedSkipsSuspendedChannel(): void
    {
        $this->channelRepository->expects(self::never())->method('findByChannelName');
        $this->channelRepository->expects(self::never())->method('iterateAll');
        $this->channelLookup->expects(self::never())->method('findByChannelName');
        $this->channelLookup->expects(self::never())->method('iterateModeSnapshots');
        $this->modeSupportProvider->expects(self::never())->method('getSupport');
        $this->modeSupport->expects(self::never())->method('getChannelRegisteredModeLetter');
        $this->modeSupport->expects(self::never())->method('getPermanentChannelModeLetter');
        $this->channelServiceActions->expects(self::never())->method('setChannelModes');
        $this->logger->expects(self::never())->method('warning');
        $this->logger->expects(self::never())->method('debug');

        $registered = $this->createStub(RegisteredChannel::class);
        $registered->method('isSuspended')->willReturn(true);
        $registered->method('isBlocked')->willReturn(true);

        $localChannelRepository = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $localChannelRepository->method('findByChannelName')->willReturn($registered);

        $localChannelLookup = $this->createStub(ChannelLookupPort::class);
        $localModeSupport = $this->createStub(ChannelModeSupportInterface::class);
        $localModeSupportProvider = $this->createStub(ActiveChannelModeSupportProviderInterface::class);
        $localChannelServiceActions = $this->createMock(ChannelServiceActionsPort::class);
        $localChannelServiceActions->expects(self::never())->method('setChannelModes');

        $subscriber = $this->createSubscriber(
            $localChannelRepository,
            $localChannelLookup,
            $localModeSupportProvider,
            $localChannelServiceActions,
            $this->createStub(LoggerInterface::class),
        );

        $event = new ChannelSynchronizedEvent('#test', channelSetupApplicable: true);
        $subscriber->onChannelSyncedSetRegistered($event);
    }

    #[Test]
    public function onSyncCompleteReconcileRegisteredSkipsSuspendedChannel(): void
    {
        $this->channelRepository->expects(self::never())->method('findByChannelName');
        $this->channelRepository->expects(self::never())->method('iterateAll');
        $this->channelLookup->expects(self::never())->method('findByChannelName');
        $this->channelLookup->expects(self::never())->method('iterateModeSnapshots');
        $this->modeSupportProvider->expects(self::never())->method('getSupport');
        $this->modeSupport->expects(self::never())->method('getChannelRegisteredModeLetter');
        $this->modeSupport->expects(self::never())->method('getPermanentChannelModeLetter');
        $this->channelServiceActions->expects(self::never())->method('setChannelModes');
        $this->logger->expects(self::never())->method('warning');
        $this->logger->expects(self::never())->method('debug');

        $registered = $this->createStub(RegisteredChannel::class);
        $registered->method('getName')->willReturn('#test');
        $registered->method('isSuspended')->willReturn(true);
        $registered->method('isBlocked')->willReturn(true);

        $localChannelRepository = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $localChannelRepository->method('iterateAll')->willReturn([$registered]);

        $localModeSupport = $this->createStub(ChannelModeSupportInterface::class);
        $localModeSupport->method('getChannelRegisteredModeLetter')->willReturn('r');

        $localModeSupportProvider = $this->createStub(ActiveChannelModeSupportProviderInterface::class);
        $localModeSupportProvider->method('getSupport')->willReturn($localModeSupport);

        $view = new ChannelView('#test', '+nt', null, 1);

        $localChannelLookup = $this->createStub(ChannelLookupPort::class);
        $localChannelLookup->method('iterateModeSnapshots')->willReturn([$view]);

        $localChannelServiceActions = $this->createMock(ChannelServiceActionsPort::class);
        $localChannelServiceActions->expects(self::never())->method('setChannelModes');

        $subscriber = $this->createSubscriber(
            $localChannelRepository,
            $localChannelLookup,
            $localModeSupportProvider,
            $localChannelServiceActions,
            $this->createStub(LoggerInterface::class),
        );
        $event = new NetworkSynchronizationCompletedEvent('001');
        $subscriber->onSyncCompleteReconcileRegisteredMode($event);
    }

    #[Test]
    public function onSyncCompleteReconcilePermanentSkipsSuspendedChannel(): void
    {
        $this->channelRepository->expects(self::never())->method('findByChannelName');
        $this->channelRepository->expects(self::never())->method('iterateAll');
        $this->channelLookup->expects(self::never())->method('findByChannelName');
        $this->channelLookup->expects(self::never())->method('iterateModeSnapshots');
        $this->modeSupportProvider->expects(self::never())->method('getSupport');
        $this->modeSupport->expects(self::never())->method('getChannelRegisteredModeLetter');
        $this->modeSupport->expects(self::never())->method('getPermanentChannelModeLetter');
        $this->channelServiceActions->expects(self::never())->method('setChannelModes');
        $this->logger->expects(self::never())->method('warning');
        $this->logger->expects(self::never())->method('debug');

        $registered = $this->createStub(RegisteredChannel::class);
        $registered->method('getName')->willReturn('#test');
        $registered->method('isSuspended')->willReturn(true);
        $registered->method('isBlocked')->willReturn(true);

        $localChannelRepository = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $localChannelRepository->method('iterateAll')->willReturn([$registered]);

        $localModeSupport = $this->createStub(ChannelModeSupportInterface::class);
        $localModeSupport->method('getPermanentChannelModeLetter')->willReturn('P');

        $localModeSupportProvider = $this->createStub(ActiveChannelModeSupportProviderInterface::class);
        $localModeSupportProvider->method('getSupport')->willReturn($localModeSupport);

        $localChannelLookup = $this->createStub(ChannelLookupPort::class);
        $localChannelLookup->method('iterateModeSnapshots')->willReturn([]);

        $localChannelServiceActions = $this->createMock(ChannelServiceActionsPort::class);
        $localChannelServiceActions->expects(self::never())->method('setChannelModes');

        $subscriber = $this->createSubscriber(
            $localChannelRepository,
            $localChannelLookup,
            $localModeSupportProvider,
            $localChannelServiceActions,
            $this->createStub(LoggerInterface::class),
        );
        $event = new NetworkSynchronizationCompletedEvent('001');
        $subscriber->onSyncCompleteReconcilePermanentMode($event);
    }

    private function createSubscriber(
        RegisteredChannelRepositoryInterface $channelRepository,
        ChannelLookupPort $channelLookup,
        ActiveChannelModeSupportProviderInterface $modeSupportProvider,
        ChannelServiceActionsPort $channelServiceActions,
        LoggerInterface $logger,
    ): ChanServRejoinSubscriber {
        $networkActions = new IrcChannelRegistrationNetworkActions(
            $modeSupportProvider,
            $channelLookup,
            $channelServiceActions,
            $logger,
        );
        $registrationLifecycle = new ChannelRegistrationService($channelRepository, $networkActions);

        return new ChanServRejoinSubscriber($registrationLifecycle);
    }
}
