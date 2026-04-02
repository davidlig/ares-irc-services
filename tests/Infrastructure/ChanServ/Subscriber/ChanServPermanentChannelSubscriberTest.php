<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\ChanServ\Subscriber;

use App\Application\Port\ActiveChannelModeSupportProviderInterface;
use App\Application\Port\ChannelLookupPort;
use App\Application\Port\ChannelModeSupportInterface;
use App\Application\Port\ChannelServiceActionsPort;
use App\Application\Port\ChannelView;
use App\Domain\ChanServ\Event\ChannelDropEvent;
use App\Domain\ChanServ\Event\ChannelRegisteredEvent;
use App\Infrastructure\ChanServ\Subscriber\ChanServPermanentChannelSubscriber;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ChanServPermanentChannelSubscriber::class)]
final class ChanServPermanentChannelSubscriberTest extends TestCase
{
    #[Test]
    public function subscribesToCorrectEvents(): void
    {
        self::assertSame(
            [
                ChannelRegisteredEvent::class => ['onChannelRegistered', 0],
                ChannelDropEvent::class => ['onChannelDrop', 0],
            ],
            ChanServPermanentChannelSubscriber::getSubscribedEvents(),
        );
    }

    #[Test]
    public function onChannelRegisteredSetsBothRegisteredAndPermanentModes(): void
    {
        $modeSupport = $this->createMock(ChannelModeSupportInterface::class);
        $modeSupport->expects(self::once())->method('getChannelRegisteredModeLetter')->willReturn('r');
        $modeSupport->expects(self::once())->method('getPermanentChannelModeLetter')->willReturn('P');

        $modeSupportProvider = $this->createMock(ActiveChannelModeSupportProviderInterface::class);
        $modeSupportProvider->expects(self::once())->method('getSupport')->willReturn($modeSupport);

        $channelLookup = $this->createMock(ChannelLookupPort::class);
        $channelLookup->expects(self::once())->method('findByChannelName')->with('#test')->willReturn(new ChannelView('#test', '+nt', null, 1));

        $channelServiceActions = $this->createMock(ChannelServiceActionsPort::class);
        $channelServiceActions->expects(self::once())->method('setChannelModes')->with('#test', '+rP', []);

        $subscriber = new ChanServPermanentChannelSubscriber($modeSupportProvider, $channelLookup, $channelServiceActions);
        $subscriber->onChannelRegistered(new ChannelRegisteredEvent(42, '#test', '#test'));
    }

    #[Test]
    public function onChannelRegisteredSetsOnlyRegisteredModeWhenPermanentNotSupported(): void
    {
        $modeSupport = $this->createMock(ChannelModeSupportInterface::class);
        $modeSupport->expects(self::once())->method('getChannelRegisteredModeLetter')->willReturn('r');
        $modeSupport->expects(self::once())->method('getPermanentChannelModeLetter')->willReturn(null);

        $modeSupportProvider = $this->createMock(ActiveChannelModeSupportProviderInterface::class);
        $modeSupportProvider->expects(self::once())->method('getSupport')->willReturn($modeSupport);

        $channelLookup = $this->createMock(ChannelLookupPort::class);
        $channelLookup->expects(self::once())->method('findByChannelName')->with('#test')->willReturn(new ChannelView('#test', '+nt', null, 1));

        $channelServiceActions = $this->createMock(ChannelServiceActionsPort::class);
        $channelServiceActions->expects(self::once())->method('setChannelModes')->with('#test', '+r', []);

        $subscriber = new ChanServPermanentChannelSubscriber($modeSupportProvider, $channelLookup, $channelServiceActions);
        $subscriber->onChannelRegistered(new ChannelRegisteredEvent(42, '#test', '#test'));
    }

    #[Test]
    public function onChannelRegisteredSetsOnlyPermanentModeWhenRegisteredNotSupported(): void
    {
        $modeSupport = $this->createMock(ChannelModeSupportInterface::class);
        $modeSupport->expects(self::once())->method('getChannelRegisteredModeLetter')->willReturn(null);
        $modeSupport->expects(self::once())->method('getPermanentChannelModeLetter')->willReturn('P');

        $modeSupportProvider = $this->createMock(ActiveChannelModeSupportProviderInterface::class);
        $modeSupportProvider->expects(self::once())->method('getSupport')->willReturn($modeSupport);

        $channelLookup = $this->createMock(ChannelLookupPort::class);
        $channelLookup->expects(self::once())->method('findByChannelName')->with('#test')->willReturn(new ChannelView('#test', '+nt', null, 1));

        $channelServiceActions = $this->createMock(ChannelServiceActionsPort::class);
        $channelServiceActions->expects(self::once())->method('setChannelModes')->with('#test', '+P', []);

        $subscriber = new ChanServPermanentChannelSubscriber($modeSupportProvider, $channelLookup, $channelServiceActions);
        $subscriber->onChannelRegistered(new ChannelRegisteredEvent(42, '#test', '#test'));
    }

    #[Test]
    public function onChannelRegisteredDoesNothingWhenBothModesNotSupported(): void
    {
        $modeSupport = $this->createMock(ChannelModeSupportInterface::class);
        $modeSupport->expects(self::once())->method('getChannelRegisteredModeLetter')->willReturn(null);
        $modeSupport->expects(self::once())->method('getPermanentChannelModeLetter')->willReturn(null);

        $modeSupportProvider = $this->createMock(ActiveChannelModeSupportProviderInterface::class);
        $modeSupportProvider->expects(self::once())->method('getSupport')->willReturn($modeSupport);

        $channelLookup = $this->createMock(ChannelLookupPort::class);
        $channelLookup->expects(self::once())->method('findByChannelName')->with('#test')->willReturn(new ChannelView('#test', '+nt', null, 1));

        $channelServiceActions = $this->createMock(ChannelServiceActionsPort::class);
        $channelServiceActions->expects(self::never())->method('setChannelModes');

        $subscriber = new ChanServPermanentChannelSubscriber($modeSupportProvider, $channelLookup, $channelServiceActions);
        $subscriber->onChannelRegistered(new ChannelRegisteredEvent(42, '#test', '#test'));
    }

    #[Test]
    public function onChannelRegisteredDoesNothingWhenChannelNotOnNetwork(): void
    {
        $modeSupportProvider = $this->createStub(ActiveChannelModeSupportProviderInterface::class);
        $channelLookup = $this->createMock(ChannelLookupPort::class);
        $channelLookup->expects(self::once())->method('findByChannelName')->with('#test')->willReturn(null);

        $channelServiceActions = $this->createMock(ChannelServiceActionsPort::class);
        $channelServiceActions->expects(self::never())->method('setChannelModes');

        $subscriber = new ChanServPermanentChannelSubscriber($modeSupportProvider, $channelLookup, $channelServiceActions);
        $subscriber->onChannelRegistered(new ChannelRegisteredEvent(42, '#test', '#test'));
    }

    #[Test]
    public function onChannelRegisteredSkipsModesAlreadyPresent(): void
    {
        $modeSupport = $this->createMock(ChannelModeSupportInterface::class);
        $modeSupport->expects(self::once())->method('getChannelRegisteredModeLetter')->willReturn('r');
        $modeSupport->expects(self::once())->method('getPermanentChannelModeLetter')->willReturn('P');

        $modeSupportProvider = $this->createMock(ActiveChannelModeSupportProviderInterface::class);
        $modeSupportProvider->expects(self::once())->method('getSupport')->willReturn($modeSupport);

        $channelLookup = $this->createMock(ChannelLookupPort::class);
        $channelLookup->expects(self::once())->method('findByChannelName')->with('#test')->willReturn(new ChannelView('#test', '+ntr', null, 1));

        $channelServiceActions = $this->createMock(ChannelServiceActionsPort::class);
        $channelServiceActions->expects(self::once())->method('setChannelModes')->with('#test', '+P', []);

        $subscriber = new ChanServPermanentChannelSubscriber($modeSupportProvider, $channelLookup, $channelServiceActions);
        $subscriber->onChannelRegistered(new ChannelRegisteredEvent(42, '#test', '#test'));
    }

    #[Test]
    public function onChannelRegisteredDoesNothingWhenAllModesAlreadyPresent(): void
    {
        $modeSupport = $this->createMock(ChannelModeSupportInterface::class);
        $modeSupport->expects(self::once())->method('getChannelRegisteredModeLetter')->willReturn('r');
        $modeSupport->expects(self::once())->method('getPermanentChannelModeLetter')->willReturn('P');

        $modeSupportProvider = $this->createMock(ActiveChannelModeSupportProviderInterface::class);
        $modeSupportProvider->expects(self::once())->method('getSupport')->willReturn($modeSupport);

        $channelLookup = $this->createMock(ChannelLookupPort::class);
        $channelLookup->expects(self::once())->method('findByChannelName')->with('#test')->willReturn(new ChannelView('#test', '+ntrP', null, 1));

        $channelServiceActions = $this->createMock(ChannelServiceActionsPort::class);
        $channelServiceActions->expects(self::never())->method('setChannelModes');

        $subscriber = new ChanServPermanentChannelSubscriber($modeSupportProvider, $channelLookup, $channelServiceActions);
        $subscriber->onChannelRegistered(new ChannelRegisteredEvent(42, '#test', '#test'));
    }

    #[Test]
    public function onChannelDropRemovesBothRegisteredAndPermanentModes(): void
    {
        $modeSupport = $this->createMock(ChannelModeSupportInterface::class);
        $modeSupport->expects(self::once())->method('getChannelRegisteredModeLetter')->willReturn('r');
        $modeSupport->expects(self::once())->method('getPermanentChannelModeLetter')->willReturn('P');

        $modeSupportProvider = $this->createMock(ActiveChannelModeSupportProviderInterface::class);
        $modeSupportProvider->expects(self::once())->method('getSupport')->willReturn($modeSupport);

        $channelLookup = $this->createMock(ChannelLookupPort::class);
        $channelLookup->expects(self::once())->method('findByChannelName')->with('#test')->willReturn(new ChannelView('#test', '+ntrP', null, 1));

        $channelServiceActions = $this->createMock(ChannelServiceActionsPort::class);
        $channelServiceActions->expects(self::once())->method('setChannelModes')->with('#test', '-rP', []);

        $subscriber = new ChanServPermanentChannelSubscriber($modeSupportProvider, $channelLookup, $channelServiceActions);
        $subscriber->onChannelDrop(new ChannelDropEvent(42, '#test', '#test', 'inactivity'));
    }

    #[Test]
    public function onChannelDropRemovesOnlyModesPresent(): void
    {
        $modeSupport = $this->createMock(ChannelModeSupportInterface::class);
        $modeSupport->expects(self::once())->method('getChannelRegisteredModeLetter')->willReturn('r');
        $modeSupport->expects(self::once())->method('getPermanentChannelModeLetter')->willReturn('P');

        $modeSupportProvider = $this->createMock(ActiveChannelModeSupportProviderInterface::class);
        $modeSupportProvider->expects(self::once())->method('getSupport')->willReturn($modeSupport);

        $channelLookup = $this->createMock(ChannelLookupPort::class);
        $channelLookup->expects(self::once())->method('findByChannelName')->with('#test')->willReturn(new ChannelView('#test', '+ntP', null, 1));

        $channelServiceActions = $this->createMock(ChannelServiceActionsPort::class);
        $channelServiceActions->expects(self::once())->method('setChannelModes')->with('#test', '-P', []);

        $subscriber = new ChanServPermanentChannelSubscriber($modeSupportProvider, $channelLookup, $channelServiceActions);
        $subscriber->onChannelDrop(new ChannelDropEvent(42, '#test', '#test', 'manual'));
    }

    #[Test]
    public function onChannelDropDoesNothingWhenBothModesNotSupported(): void
    {
        $modeSupport = $this->createMock(ChannelModeSupportInterface::class);
        $modeSupport->expects(self::once())->method('getChannelRegisteredModeLetter')->willReturn(null);
        $modeSupport->expects(self::once())->method('getPermanentChannelModeLetter')->willReturn(null);

        $modeSupportProvider = $this->createMock(ActiveChannelModeSupportProviderInterface::class);
        $modeSupportProvider->expects(self::once())->method('getSupport')->willReturn($modeSupport);

        $channelLookup = $this->createMock(ChannelLookupPort::class);
        $channelLookup->expects(self::once())->method('findByChannelName')->with('#test')->willReturn(new ChannelView('#test', '+nt', null, 1));

        $channelServiceActions = $this->createMock(ChannelServiceActionsPort::class);
        $channelServiceActions->expects(self::never())->method('setChannelModes');

        $subscriber = new ChanServPermanentChannelSubscriber($modeSupportProvider, $channelLookup, $channelServiceActions);
        $subscriber->onChannelDrop(new ChannelDropEvent(42, '#test', '#test', 'manual'));
    }

    #[Test]
    public function onChannelDropDoesNothingWhenChannelNotOnNetwork(): void
    {
        $modeSupportProvider = $this->createStub(ActiveChannelModeSupportProviderInterface::class);
        $channelLookup = $this->createMock(ChannelLookupPort::class);
        $channelLookup->expects(self::once())->method('findByChannelName')->with('#test')->willReturn(null);

        $channelServiceActions = $this->createMock(ChannelServiceActionsPort::class);
        $channelServiceActions->expects(self::never())->method('setChannelModes');

        $subscriber = new ChanServPermanentChannelSubscriber($modeSupportProvider, $channelLookup, $channelServiceActions);
        $subscriber->onChannelDrop(new ChannelDropEvent(42, '#test', '#test', 'manual'));
    }

    #[Test]
    public function onChannelDropDoesNothingWhenNoModesPresent(): void
    {
        $modeSupport = $this->createMock(ChannelModeSupportInterface::class);
        $modeSupport->expects(self::once())->method('getChannelRegisteredModeLetter')->willReturn('r');
        $modeSupport->expects(self::once())->method('getPermanentChannelModeLetter')->willReturn('P');

        $modeSupportProvider = $this->createMock(ActiveChannelModeSupportProviderInterface::class);
        $modeSupportProvider->expects(self::once())->method('getSupport')->willReturn($modeSupport);

        $channelLookup = $this->createMock(ChannelLookupPort::class);
        $channelLookup->expects(self::once())->method('findByChannelName')->with('#test')->willReturn(new ChannelView('#test', '+nt', null, 1));

        $channelServiceActions = $this->createMock(ChannelServiceActionsPort::class);
        $channelServiceActions->expects(self::never())->method('setChannelModes');

        $subscriber = new ChanServPermanentChannelSubscriber($modeSupportProvider, $channelLookup, $channelServiceActions);
        $subscriber->onChannelDrop(new ChannelDropEvent(42, '#test', '#test', 'manual'));
    }
}
