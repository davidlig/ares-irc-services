<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\IRC\Subscriber;

use App\Application\Port\ActiveChannelModeSupportProviderInterface;
use App\Application\Port\ChannelLookupPort;
use App\Application\Port\ChannelModeSupportInterface;
use App\Application\Port\ChannelServiceActionsPort;
use App\Application\Port\ChannelView;
use App\Application\Port\ServiceDebugNotifierInterface;
use App\Domain\ChanServ\Entity\RegisteredChannel;
use App\Domain\ChanServ\Repository\RegisteredChannelRepositoryInterface;
use App\Domain\IRC\Connection\ConnectionInterface;
use App\Domain\IRC\Event\NetworkBurstCompleteEvent;
use App\Domain\IRC\Event\NetworkSyncCompleteEvent;
use App\Infrastructure\IRC\Subscriber\DebugChannelJoinSubscriber;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(DebugChannelJoinSubscriber::class)]
final class DebugChannelJoinSubscriberTest extends TestCase
{
    #[Test]
    public function getSubscribedEventsReturnsExpectedEvents(): void
    {
        $events = DebugChannelJoinSubscriber::getSubscribedEvents();

        self::assertArrayHasKey(NetworkBurstCompleteEvent::class, $events);
        self::assertSame(['onBurstComplete', 0], $events[NetworkBurstCompleteEvent::class]);
        self::assertArrayHasKey(NetworkSyncCompleteEvent::class, $events);
        self::assertSame(['onSyncComplete', -30], $events[NetworkSyncCompleteEvent::class]);
    }

    #[Test]
    public function onBurstCompleteCallsEnsureChannelJoinedOnAllNotifiers(): void
    {
        $notifier1 = $this->createMock(ServiceDebugNotifierInterface::class);
        $notifier1->expects(self::once())->method('ensureChannelJoined');

        $notifier2 = $this->createMock(ServiceDebugNotifierInterface::class);
        $notifier2->expects(self::once())->method('ensureChannelJoined');

        $subscriber = $this->createSubscriber(debugNotifiers: [$notifier1, $notifier2]);

        $connection = $this->createStub(ConnectionInterface::class);
        $event = new NetworkBurstCompleteEvent($connection, '001');
        $subscriber->onBurstComplete($event);
    }

    #[Test]
    public function onBurstCompleteHandlesEmptyNotifiersArray(): void
    {
        $subscriber = $this->createSubscriber(debugNotifiers: []);

        $connection = $this->createStub(ConnectionInterface::class);
        $event = new NetworkBurstCompleteEvent($connection, '001');

        $subscriber->onBurstComplete($event);

        self::assertTrue(true);
    }

    #[Test]
    public function onSyncCompleteDoesNothingWhenDebugChannelIsNull(): void
    {
        $registeredRepo = $this->createMock(RegisteredChannelRepositoryInterface::class);
        $registeredRepo->expects(self::never())->method('findByChannelName');

        $subscriber = $this->createSubscriber(
            debugChannel: null,
            registeredChannelRepository: $registeredRepo,
        );

        $event = new NetworkSyncCompleteEvent($this->createStub(ConnectionInterface::class), '001');
        $subscriber->onSyncComplete($event);
    }

    #[Test]
    public function onSyncCompleteDoesNothingWhenDebugChannelIsEmpty(): void
    {
        $registeredRepo = $this->createMock(RegisteredChannelRepositoryInterface::class);
        $registeredRepo->expects(self::never())->method('findByChannelName');

        $subscriber = $this->createSubscriber(
            debugChannel: '',
            registeredChannelRepository: $registeredRepo,
        );

        $event = new NetworkSyncCompleteEvent($this->createStub(ConnectionInterface::class), '001');
        $subscriber->onSyncComplete($event);
    }

    #[Test]
    public function onSyncCompleteDoesNothingWhenChannelIsNotRegistered(): void
    {
        $registeredRepo = $this->createMock(RegisteredChannelRepositoryInterface::class);
        $registeredRepo->expects(self::once())->method('findByChannelName')->willReturn(null);

        $subscriber = $this->createSubscriber(
            debugChannel: '#opers',
            registeredChannelRepository: $registeredRepo,
        );

        $event = new NetworkSyncCompleteEvent($this->createStub(ConnectionInterface::class), '001');
        $subscriber->onSyncComplete($event);
    }

    #[Test]
    public function onSyncCompleteDoesNothingWhenChannelIsSuspended(): void
    {
        $registered = $this->createStub(RegisteredChannel::class);
        $registered->method('isSuspended')->willReturn(true);

        $registeredRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $registeredRepo->method('findByChannelName')->willReturn($registered);

        $channelActions = $this->createMock(ChannelServiceActionsPort::class);
        $channelActions->expects(self::never())->method('setChannelModes');

        $subscriber = $this->createSubscriber(
            debugChannel: '#opers',
            registeredChannelRepository: $registeredRepo,
            channelServiceActions: $channelActions,
        );

        $event = new NetworkSyncCompleteEvent($this->createStub(ConnectionInterface::class), '001');
        $subscriber->onSyncComplete($event);
    }

    #[Test]
    public function onSyncCompleteDoesNothingWhenChannelIsForbidden(): void
    {
        $registered = $this->createStub(RegisteredChannel::class);
        $registered->method('isForbidden')->willReturn(true);

        $registeredRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $registeredRepo->method('findByChannelName')->willReturn($registered);

        $channelActions = $this->createMock(ChannelServiceActionsPort::class);
        $channelActions->expects(self::never())->method('setChannelModes');

        $subscriber = $this->createSubscriber(
            debugChannel: '#opers',
            registeredChannelRepository: $registeredRepo,
            channelServiceActions: $channelActions,
        );

        $event = new NetworkSyncCompleteEvent($this->createStub(ConnectionInterface::class), '001');
        $subscriber->onSyncComplete($event);
    }

    #[Test]
    public function onSyncCompleteDoesNothingWhenChannelExistsInLookup(): void
    {
        $registered = $this->createStub(RegisteredChannel::class);
        $registered->method('isSuspended')->willReturn(false);
        $registered->method('isForbidden')->willReturn(false);

        $registeredRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $registeredRepo->method('findByChannelName')->willReturn($registered);

        $channelLookup = $this->createStub(ChannelLookupPort::class);
        $channelLookup->method('findByChannelName')->willReturn(
            new ChannelView(name: '#opers', modes: '+r', topic: null, memberCount: 1),
        );

        $channelActions = $this->createMock(ChannelServiceActionsPort::class);
        $channelActions->expects(self::never())->method('setChannelModes');

        $subscriber = $this->createSubscriber(
            debugChannel: '#opers',
            registeredChannelRepository: $registeredRepo,
            channelLookup: $channelLookup,
            channelServiceActions: $channelActions,
        );

        $event = new NetworkSyncCompleteEvent($this->createStub(ConnectionInterface::class), '001');
        $subscriber->onSyncComplete($event);
    }

    #[Test]
    public function onSyncCompleteAppliesRegisteredModeWhenChannelMissingFromLookup(): void
    {
        $registered = $this->createStub(RegisteredChannel::class);
        $registered->method('isSuspended')->willReturn(false);
        $registered->method('isForbidden')->willReturn(false);
        $registered->method('isMlockActive')->willReturn(false);
        $registered->method('getTopic')->willReturn(null);

        $registeredRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $registeredRepo->method('findByChannelName')->willReturn($registered);

        $channelLookup = $this->createStub(ChannelLookupPort::class);
        $channelLookup->method('findByChannelName')->willReturn(null);

        $modeSupport = $this->createStub(ChannelModeSupportInterface::class);
        $modeSupport->method('getChannelRegisteredModeLetter')->willReturn('r');
        $modeSupport->method('getPermanentChannelModeLetter')->willReturn(null);
        $modeSupport->method('getSupportedPrefixModes')->willReturn(['o', 'v']);

        $modeSupportProvider = $this->createStub(ActiveChannelModeSupportProviderInterface::class);
        $modeSupportProvider->method('getSupport')->willReturn($modeSupport);

        $channelActions = $this->createMock(ChannelServiceActionsPort::class);
        $channelActions->expects(self::once())->method('setChannelModes')->with('#opers', '+r', []);
        $channelActions->expects(self::once())->method('setChannelMemberMode')->with('#opers', '0A0BBBBBB', 'o', true);

        $subscriber = $this->createSubscriber(
            debugChannel: '#opers',
            registeredChannelRepository: $registeredRepo,
            channelLookup: $channelLookup,
            channelServiceActions: $channelActions,
            modeSupportProvider: $modeSupportProvider,
        );

        $event = new NetworkSyncCompleteEvent($this->createStub(ConnectionInterface::class), '001');
        $subscriber->onSyncComplete($event);
    }

    #[Test]
    public function onSyncCompleteAppliesRegisteredAndPermanentModes(): void
    {
        $registered = $this->createStub(RegisteredChannel::class);
        $registered->method('isSuspended')->willReturn(false);
        $registered->method('isForbidden')->willReturn(false);
        $registered->method('isMlockActive')->willReturn(false);
        $registered->method('getTopic')->willReturn(null);

        $registeredRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $registeredRepo->method('findByChannelName')->willReturn($registered);

        $channelLookup = $this->createStub(ChannelLookupPort::class);
        $channelLookup->method('findByChannelName')->willReturn(null);

        $modeSupport = $this->createStub(ChannelModeSupportInterface::class);
        $modeSupport->method('getChannelRegisteredModeLetter')->willReturn('r');
        $modeSupport->method('getPermanentChannelModeLetter')->willReturn('P');
        $modeSupport->method('getSupportedPrefixModes')->willReturn(['q', 'a', 'o', 'v']);

        $modeSupportProvider = $this->createStub(ActiveChannelModeSupportProviderInterface::class);
        $modeSupportProvider->method('getSupport')->willReturn($modeSupport);

        $channelActions = $this->createMock(ChannelServiceActionsPort::class);
        $channelActions->expects(self::once())->method('setChannelModes')->with('#opers', '+rP', []);
        $channelActions->expects(self::once())->method('setChannelMemberMode')->with('#opers', '0A0BBBBBB', 'q', true);

        $subscriber = $this->createSubscriber(
            debugChannel: '#opers',
            registeredChannelRepository: $registeredRepo,
            channelLookup: $channelLookup,
            channelServiceActions: $channelActions,
            modeSupportProvider: $modeSupportProvider,
        );

        $event = new NetworkSyncCompleteEvent($this->createStub(ConnectionInterface::class), '001');
        $subscriber->onSyncComplete($event);
    }

    #[Test]
    public function onSyncCompleteAppliesMlockWhenActive(): void
    {
        $registered = $this->createStub(RegisteredChannel::class);
        $registered->method('isSuspended')->willReturn(false);
        $registered->method('isForbidden')->willReturn(false);
        $registered->method('isMlockActive')->willReturn(true);
        $registered->method('getMlock')->willReturn('nt');
        $registered->method('getTopic')->willReturn(null);
        $registered->method('getMlockParam')->willReturn(null);

        $registeredRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $registeredRepo->method('findByChannelName')->willReturn($registered);

        $channelLookup = $this->createStub(ChannelLookupPort::class);
        $channelLookup->method('findByChannelName')->willReturn(null);

        $modeSupport = $this->createStub(ChannelModeSupportInterface::class);
        $modeSupport->method('getChannelRegisteredModeLetter')->willReturn('r');
        $modeSupport->method('getPermanentChannelModeLetter')->willReturn(null);
        $modeSupport->method('getSupportedPrefixModes')->willReturn(['o', 'v']);
        $modeSupport->method('getChannelSettingModesWithParamOnSet')->willReturn([]);

        $modeSupportProvider = $this->createStub(ActiveChannelModeSupportProviderInterface::class);
        $modeSupportProvider->method('getSupport')->willReturn($modeSupport);

        $invocationCount = 0;
        $channelActions = $this->createMock(ChannelServiceActionsPort::class);
        $channelActions->expects(self::exactly(2))->method('setChannelModes')
            ->willReturnCallback(static function (string $channel, string $modes, array $params) use (&$invocationCount): void {
                ++$invocationCount;
                if (1 === $invocationCount) {
                    self::assertSame('#opers', $channel);
                    self::assertSame('+r', $modes);
                    self::assertSame([], $params);
                } else {
                    self::assertSame('#opers', $channel);
                    self::assertSame('+nt', $modes);
                    self::assertSame([], $params);
                }
            });

        $subscriber = $this->createSubscriber(
            debugChannel: '#opers',
            registeredChannelRepository: $registeredRepo,
            channelLookup: $channelLookup,
            channelServiceActions: $channelActions,
            modeSupportProvider: $modeSupportProvider,
        );

        $event = new NetworkSyncCompleteEvent($this->createStub(ConnectionInterface::class), '001');
        $subscriber->onSyncComplete($event);
    }

    #[Test]
    public function onSyncCompleteAppliesTopicWhenStored(): void
    {
        $registered = $this->createStub(RegisteredChannel::class);
        $registered->method('isSuspended')->willReturn(false);
        $registered->method('isForbidden')->willReturn(false);
        $registered->method('isMlockActive')->willReturn(false);
        $registered->method('getTopic')->willReturn('Official ops channel');

        $registeredRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $registeredRepo->method('findByChannelName')->willReturn($registered);

        $channelLookup = $this->createStub(ChannelLookupPort::class);
        $channelLookup->method('findByChannelName')->willReturn(null);

        $modeSupport = $this->createStub(ChannelModeSupportInterface::class);
        $modeSupport->method('getChannelRegisteredModeLetter')->willReturn('r');
        $modeSupport->method('getPermanentChannelModeLetter')->willReturn(null);
        $modeSupport->method('getSupportedPrefixModes')->willReturn(['o', 'v']);

        $modeSupportProvider = $this->createStub(ActiveChannelModeSupportProviderInterface::class);
        $modeSupportProvider->method('getSupport')->willReturn($modeSupport);

        $channelActions = $this->createMock(ChannelServiceActionsPort::class);
        $channelActions->expects(self::once())->method('setChannelTopic')->with('#opers', 'Official ops channel');

        $subscriber = $this->createSubscriber(
            debugChannel: '#opers',
            registeredChannelRepository: $registeredRepo,
            channelLookup: $channelLookup,
            channelServiceActions: $channelActions,
            modeSupportProvider: $modeSupportProvider,
        );

        $event = new NetworkSyncCompleteEvent($this->createStub(ConnectionInterface::class), '001');
        $subscriber->onSyncComplete($event);
    }

    #[Test]
    public function onSyncCompleteAppliesNoModesWhenRegisteredLetterIsNull(): void
    {
        $registered = $this->createStub(RegisteredChannel::class);
        $registered->method('isSuspended')->willReturn(false);
        $registered->method('isForbidden')->willReturn(false);
        $registered->method('isMlockActive')->willReturn(false);
        $registered->method('getTopic')->willReturn(null);

        $registeredRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $registeredRepo->method('findByChannelName')->willReturn($registered);

        $channelLookup = $this->createStub(ChannelLookupPort::class);
        $channelLookup->method('findByChannelName')->willReturn(null);

        $modeSupport = $this->createStub(ChannelModeSupportInterface::class);
        $modeSupport->method('getChannelRegisteredModeLetter')->willReturn(null);
        $modeSupport->method('getPermanentChannelModeLetter')->willReturn(null);
        $modeSupport->method('getSupportedPrefixModes')->willReturn(['o', 'v']);

        $modeSupportProvider = $this->createStub(ActiveChannelModeSupportProviderInterface::class);
        $modeSupportProvider->method('getSupport')->willReturn($modeSupport);

        $channelActions = $this->createMock(ChannelServiceActionsPort::class);
        $channelActions->expects(self::never())->method('setChannelModes');
        $channelActions->expects(self::once())->method('setChannelMemberMode');

        $subscriber = $this->createSubscriber(
            debugChannel: '#opers',
            registeredChannelRepository: $registeredRepo,
            channelLookup: $channelLookup,
            channelServiceActions: $channelActions,
            modeSupportProvider: $modeSupportProvider,
        );

        $event = new NetworkSyncCompleteEvent($this->createStub(ConnectionInterface::class), '001');
        $subscriber->onSyncComplete($event);
    }

    #[Test]
    public function onSyncCompleteSkipsMlockWhenNotActive(): void
    {
        $registered = $this->createStub(RegisteredChannel::class);
        $registered->method('isSuspended')->willReturn(false);
        $registered->method('isForbidden')->willReturn(false);
        $registered->method('isMlockActive')->willReturn(false);
        $registered->method('getTopic')->willReturn(null);

        $registeredRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $registeredRepo->method('findByChannelName')->willReturn($registered);

        $channelLookup = $this->createStub(ChannelLookupPort::class);
        $channelLookup->method('findByChannelName')->willReturn(null);

        $modeSupport = $this->createStub(ChannelModeSupportInterface::class);
        $modeSupport->method('getChannelRegisteredModeLetter')->willReturn('r');
        $modeSupport->method('getPermanentChannelModeLetter')->willReturn(null);
        $modeSupport->method('getSupportedPrefixModes')->willReturn(['o', 'v']);

        $modeSupportProvider = $this->createStub(ActiveChannelModeSupportProviderInterface::class);
        $modeSupportProvider->method('getSupport')->willReturn($modeSupport);

        $channelActions = $this->createMock(ChannelServiceActionsPort::class);
        $channelActions->expects(self::once())->method('setChannelModes')->with('#opers', '+r', []);

        $subscriber = $this->createSubscriber(
            debugChannel: '#opers',
            registeredChannelRepository: $registeredRepo,
            channelLookup: $channelLookup,
            channelServiceActions: $channelActions,
            modeSupportProvider: $modeSupportProvider,
        );

        $event = new NetworkSyncCompleteEvent($this->createStub(ConnectionInterface::class), '001');
        $subscriber->onSyncComplete($event);
    }

    #[Test]
    public function onSyncCompleteSkipsMlockWhenEmptyString(): void
    {
        $registered = $this->createStub(RegisteredChannel::class);
        $registered->method('isSuspended')->willReturn(false);
        $registered->method('isForbidden')->willReturn(false);
        $registered->method('isMlockActive')->willReturn(true);
        $registered->method('getMlock')->willReturn('');
        $registered->method('getTopic')->willReturn(null);

        $registeredRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $registeredRepo->method('findByChannelName')->willReturn($registered);

        $channelLookup = $this->createStub(ChannelLookupPort::class);
        $channelLookup->method('findByChannelName')->willReturn(null);

        $modeSupport = $this->createStub(ChannelModeSupportInterface::class);
        $modeSupport->method('getChannelRegisteredModeLetter')->willReturn('r');
        $modeSupport->method('getPermanentChannelModeLetter')->willReturn(null);
        $modeSupport->method('getSupportedPrefixModes')->willReturn(['o', 'v']);
        $modeSupport->method('getChannelSettingModesWithParamOnSet')->willReturn([]);

        $modeSupportProvider = $this->createStub(ActiveChannelModeSupportProviderInterface::class);
        $modeSupportProvider->method('getSupport')->willReturn($modeSupport);

        $channelActions = $this->createMock(ChannelServiceActionsPort::class);
        $channelActions->expects(self::once())->method('setChannelModes')->with('#opers', '+r', []);

        $subscriber = $this->createSubscriber(
            debugChannel: '#opers',
            registeredChannelRepository: $registeredRepo,
            channelLookup: $channelLookup,
            channelServiceActions: $channelActions,
            modeSupportProvider: $modeSupportProvider,
        );

        $event = new NetworkSyncCompleteEvent($this->createStub(ConnectionInterface::class), '001');
        $subscriber->onSyncComplete($event);
    }

    #[Test]
    public function onSyncCompleteSkipsTopicWhenNull(): void
    {
        $registered = $this->createStub(RegisteredChannel::class);
        $registered->method('isSuspended')->willReturn(false);
        $registered->method('isForbidden')->willReturn(false);
        $registered->method('isMlockActive')->willReturn(false);
        $registered->method('getTopic')->willReturn(null);

        $registeredRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $registeredRepo->method('findByChannelName')->willReturn($registered);

        $channelLookup = $this->createStub(ChannelLookupPort::class);
        $channelLookup->method('findByChannelName')->willReturn(null);

        $modeSupport = $this->createStub(ChannelModeSupportInterface::class);
        $modeSupport->method('getChannelRegisteredModeLetter')->willReturn('r');
        $modeSupport->method('getPermanentChannelModeLetter')->willReturn(null);
        $modeSupport->method('getSupportedPrefixModes')->willReturn(['o', 'v']);

        $modeSupportProvider = $this->createStub(ActiveChannelModeSupportProviderInterface::class);
        $modeSupportProvider->method('getSupport')->willReturn($modeSupport);

        $channelActions = $this->createMock(ChannelServiceActionsPort::class);
        $channelActions->expects(self::never())->method('setChannelTopic');

        $subscriber = $this->createSubscriber(
            debugChannel: '#opers',
            registeredChannelRepository: $registeredRepo,
            channelLookup: $channelLookup,
            channelServiceActions: $channelActions,
            modeSupportProvider: $modeSupportProvider,
        );

        $event = new NetworkSyncCompleteEvent($this->createStub(ConnectionInterface::class), '001');
        $subscriber->onSyncComplete($event);
    }

    #[Test]
    public function onSyncCompleteUsesHighestSupportedPrefixForChanServRank(): void
    {
        $registered = $this->createStub(RegisteredChannel::class);
        $registered->method('isSuspended')->willReturn(false);
        $registered->method('isForbidden')->willReturn(false);
        $registered->method('isMlockActive')->willReturn(false);
        $registered->method('getTopic')->willReturn(null);

        $registeredRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $registeredRepo->method('findByChannelName')->willReturn($registered);

        $channelLookup = $this->createStub(ChannelLookupPort::class);
        $channelLookup->method('findByChannelName')->willReturn(null);

        $modeSupport = $this->createStub(ChannelModeSupportInterface::class);
        $modeSupport->method('getChannelRegisteredModeLetter')->willReturn(null);
        $modeSupport->method('getPermanentChannelModeLetter')->willReturn(null);
        $modeSupport->method('getSupportedPrefixModes')->willReturn(['v', 'h', 'o', 'a', 'q']);

        $modeSupportProvider = $this->createStub(ActiveChannelModeSupportProviderInterface::class);
        $modeSupportProvider->method('getSupport')->willReturn($modeSupport);

        $channelActions = $this->createMock(ChannelServiceActionsPort::class);
        $channelActions->expects(self::never())->method('setChannelModes');
        $channelActions->expects(self::once())->method('setChannelMemberMode')->with('#opers', '0A0BBBBBB', 'q', true);

        $subscriber = $this->createSubscriber(
            debugChannel: '#opers',
            registeredChannelRepository: $registeredRepo,
            channelLookup: $channelLookup,
            channelServiceActions: $channelActions,
            modeSupportProvider: $modeSupportProvider,
        );

        $event = new NetworkSyncCompleteEvent($this->createStub(ConnectionInterface::class), '001');
        $subscriber->onSyncComplete($event);
    }

    #[Test]
    public function onSyncCompleteAppliesMlockWithParams(): void
    {
        $registered = $this->createStub(RegisteredChannel::class);
        $registered->method('isSuspended')->willReturn(false);
        $registered->method('isForbidden')->willReturn(false);
        $registered->method('isMlockActive')->willReturn(true);
        $registered->method('getMlock')->willReturn('ntk');
        $registered->method('getTopic')->willReturn(null);
        $registered->method('getMlockParam')->willReturnCallback(static fn (string $letter): ?string => 'k' === $letter ? 'secretpass' : null);

        $registeredRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $registeredRepo->method('findByChannelName')->willReturn($registered);

        $channelLookup = $this->createStub(ChannelLookupPort::class);
        $channelLookup->method('findByChannelName')->willReturn(null);

        $modeSupport = $this->createStub(ChannelModeSupportInterface::class);
        $modeSupport->method('getChannelRegisteredModeLetter')->willReturn('r');
        $modeSupport->method('getPermanentChannelModeLetter')->willReturn(null);
        $modeSupport->method('getSupportedPrefixModes')->willReturn(['o', 'v']);
        $modeSupport->method('getChannelSettingModesWithParamOnSet')->willReturn(['k']);

        $modeSupportProvider = $this->createStub(ActiveChannelModeSupportProviderInterface::class);
        $modeSupportProvider->method('getSupport')->willReturn($modeSupport);

        $invocationCount = 0;
        $channelActions = $this->createMock(ChannelServiceActionsPort::class);
        $channelActions->expects(self::exactly(2))->method('setChannelModes')
            ->willReturnCallback(static function (string $channel, string $modes, array $params) use (&$invocationCount): void {
                ++$invocationCount;
                if (1 === $invocationCount) {
                    self::assertSame('#opers', $channel);
                    self::assertSame('+r', $modes);
                    self::assertSame([], $params);
                } else {
                    self::assertSame('#opers', $channel);
                    self::assertSame('+ntk', $modes);
                    self::assertSame(['secretpass'], $params);
                }
            });

        $subscriber = $this->createSubscriber(
            debugChannel: '#opers',
            registeredChannelRepository: $registeredRepo,
            channelLookup: $channelLookup,
            channelServiceActions: $channelActions,
            modeSupportProvider: $modeSupportProvider,
        );

        $event = new NetworkSyncCompleteEvent($this->createStub(ConnectionInterface::class), '001');
        $subscriber->onSyncComplete($event);
    }

    #[Test]
    public function onSyncCompleteParsesMlockWithPlusMinusSigns(): void
    {
        $registered = $this->createStub(RegisteredChannel::class);
        $registered->method('isSuspended')->willReturn(false);
        $registered->method('isForbidden')->willReturn(false);
        $registered->method('isMlockActive')->willReturn(true);
        $registered->method('getMlock')->willReturn('+nt-is');
        $registered->method('getTopic')->willReturn(null);
        $registered->method('getMlockParam')->willReturn(null);

        $registeredRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $registeredRepo->method('findByChannelName')->willReturn($registered);

        $channelLookup = $this->createStub(ChannelLookupPort::class);
        $channelLookup->method('findByChannelName')->willReturn(null);

        $modeSupport = $this->createStub(ChannelModeSupportInterface::class);
        $modeSupport->method('getChannelRegisteredModeLetter')->willReturn('r');
        $modeSupport->method('getPermanentChannelModeLetter')->willReturn(null);
        $modeSupport->method('getSupportedPrefixModes')->willReturn(['o', 'v']);
        $modeSupport->method('getChannelSettingModesWithParamOnSet')->willReturn([]);

        $modeSupportProvider = $this->createStub(ActiveChannelModeSupportProviderInterface::class);
        $modeSupportProvider->method('getSupport')->willReturn($modeSupport);

        $invocationCount = 0;
        $channelActions = $this->createMock(ChannelServiceActionsPort::class);
        $channelActions->expects(self::exactly(2))->method('setChannelModes')
            ->willReturnCallback(static function (string $channel, string $modes, array $params) use (&$invocationCount): void {
                ++$invocationCount;
                if (1 === $invocationCount) {
                    self::assertSame('+r', $modes);
                } else {
                    self::assertSame('+ntis', $modes);
                    self::assertSame([], $params);
                }
            });

        $subscriber = $this->createSubscriber(
            debugChannel: '#opers',
            registeredChannelRepository: $registeredRepo,
            channelLookup: $channelLookup,
            channelServiceActions: $channelActions,
            modeSupportProvider: $modeSupportProvider,
        );

        $event = new NetworkSyncCompleteEvent($this->createStub(ConnectionInterface::class), '001');
        $subscriber->onSyncComplete($event);
    }

    #[Test]
    public function onSyncCompleteSkipsMlockWhenOnlyPlusMinusSigns(): void
    {
        $registered = $this->createStub(RegisteredChannel::class);
        $registered->method('isSuspended')->willReturn(false);
        $registered->method('isForbidden')->willReturn(false);
        $registered->method('isMlockActive')->willReturn(true);
        $registered->method('getMlock')->willReturn('+-');
        $registered->method('getTopic')->willReturn(null);

        $registeredRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $registeredRepo->method('findByChannelName')->willReturn($registered);

        $channelLookup = $this->createStub(ChannelLookupPort::class);
        $channelLookup->method('findByChannelName')->willReturn(null);

        $modeSupport = $this->createStub(ChannelModeSupportInterface::class);
        $modeSupport->method('getChannelRegisteredModeLetter')->willReturn('r');
        $modeSupport->method('getPermanentChannelModeLetter')->willReturn(null);
        $modeSupport->method('getSupportedPrefixModes')->willReturn(['o', 'v']);
        $modeSupport->method('getChannelSettingModesWithParamOnSet')->willReturn([]);

        $modeSupportProvider = $this->createStub(ActiveChannelModeSupportProviderInterface::class);
        $modeSupportProvider->method('getSupport')->willReturn($modeSupport);

        $channelActions = $this->createMock(ChannelServiceActionsPort::class);
        $channelActions->expects(self::once())->method('setChannelModes')->with('#opers', '+r', []);

        $subscriber = $this->createSubscriber(
            debugChannel: '#opers',
            registeredChannelRepository: $registeredRepo,
            channelLookup: $channelLookup,
            channelServiceActions: $channelActions,
            modeSupportProvider: $modeSupportProvider,
        );

        $event = new NetworkSyncCompleteEvent($this->createStub(ConnectionInterface::class), '001');
        $subscriber->onSyncComplete($event);
    }

    /**
     * @param iterable<ServiceDebugNotifierInterface> $debugNotifiers
     */
    private function createSubscriber(
        ?iterable $debugNotifiers = [],
        ?string $debugChannel = '#opers',
        ?RegisteredChannelRepositoryInterface $registeredChannelRepository = null,
        ?ChannelLookupPort $channelLookup = null,
        ?ChannelServiceActionsPort $channelServiceActions = null,
        ?ActiveChannelModeSupportProviderInterface $modeSupportProvider = null,
        string $chanservUid = '0A0BBBBBB',
    ): DebugChannelJoinSubscriber {
        return new DebugChannelJoinSubscriber(
            debugNotifiers: $debugNotifiers ?? [],
            debugChannel: $debugChannel,
            registeredChannelRepository: $registeredChannelRepository ?? $this->createStub(RegisteredChannelRepositoryInterface::class),
            channelLookup: $channelLookup ?? $this->createStub(ChannelLookupPort::class),
            channelServiceActions: $channelServiceActions ?? $this->createStub(ChannelServiceActionsPort::class),
            modeSupportProvider: $modeSupportProvider ?? $this->createStub(ActiveChannelModeSupportProviderInterface::class),
            chanservUid: $chanservUid,
        );
    }
}
