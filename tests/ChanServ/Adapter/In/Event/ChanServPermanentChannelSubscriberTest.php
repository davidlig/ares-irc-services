<?php

declare(strict_types=1);

namespace App\Tests\ChanServ\Adapter\In\Event;

use App\ChanServ\Adapter\In\Event\ChanServPermanentChannelSubscriber;
use App\ChanServ\Adapter\Out\Network\IrcChannelRegistrationNetworkActions;
use App\ChanServ\Application\Port\Out\RegisteredChannelRepositoryInterface;
use App\ChanServ\Application\PublishedEvent\ChannelDropEvent;
use App\ChanServ\Application\PublishedEvent\ChannelRegisteredEvent;
use App\ChanServ\Application\Service\ChannelRegistrationService;
use App\Irc\Application\Port\In\ActiveChannelModeSupportProviderInterface;
use App\Irc\Application\Port\In\ChannelLookupPort;
use App\Irc\Application\Port\In\ChannelModeSupportInterface;
use App\Irc\Application\Port\In\ChannelServiceActionsPort;
use App\Irc\Application\Port\In\ChannelView;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ChanServPermanentChannelSubscriber::class)]
#[CoversClass(ChannelRegistrationService::class)]
#[CoversClass(IrcChannelRegistrationNetworkActions::class)]
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

        $subscriber = $this->createSubscriber($modeSupportProvider, $channelLookup, $channelServiceActions);
        $subscriber->onChannelRegistered(new ChannelRegisteredEvent(42, '#test', '#test', new DateTimeImmutable('2026-01-02 03:04:05')));
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

        $subscriber = $this->createSubscriber($modeSupportProvider, $channelLookup, $channelServiceActions);
        $subscriber->onChannelRegistered(new ChannelRegisteredEvent(42, '#test', '#test', new DateTimeImmutable('2026-01-02 03:04:05')));
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

        $subscriber = $this->createSubscriber($modeSupportProvider, $channelLookup, $channelServiceActions);
        $subscriber->onChannelRegistered(new ChannelRegisteredEvent(42, '#test', '#test', new DateTimeImmutable('2026-01-02 03:04:05')));
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

        $subscriber = $this->createSubscriber($modeSupportProvider, $channelLookup, $channelServiceActions);
        $subscriber->onChannelRegistered(new ChannelRegisteredEvent(42, '#test', '#test', new DateTimeImmutable('2026-01-02 03:04:05')));
    }

    #[Test]
    public function onChannelRegisteredDoesNothingWhenChannelNotOnNetwork(): void
    {
        $modeSupportProvider = $this->createStub(ActiveChannelModeSupportProviderInterface::class);
        $channelLookup = $this->createMock(ChannelLookupPort::class);
        $channelLookup->expects(self::once())->method('findByChannelName')->with('#test')->willReturn(null);

        $channelServiceActions = $this->createMock(ChannelServiceActionsPort::class);
        $channelServiceActions->expects(self::never())->method('setChannelModes');

        $subscriber = $this->createSubscriber($modeSupportProvider, $channelLookup, $channelServiceActions);
        $subscriber->onChannelRegistered(new ChannelRegisteredEvent(42, '#test', '#test', new DateTimeImmutable('2026-01-02 03:04:05')));
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

        $subscriber = $this->createSubscriber($modeSupportProvider, $channelLookup, $channelServiceActions);
        $subscriber->onChannelRegistered(new ChannelRegisteredEvent(42, '#test', '#test', new DateTimeImmutable('2026-01-02 03:04:05')));
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

        $subscriber = $this->createSubscriber($modeSupportProvider, $channelLookup, $channelServiceActions);
        $subscriber->onChannelRegistered(new ChannelRegisteredEvent(42, '#test', '#test', new DateTimeImmutable('2026-01-02 03:04:05')));
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

        $subscriber = $this->createSubscriber($modeSupportProvider, $channelLookup, $channelServiceActions);
        $subscriber->onChannelDrop(new ChannelDropEvent(42, '#test', '#test', 'inactivity', new DateTimeImmutable('2026-01-02 03:04:05')));
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

        $subscriber = $this->createSubscriber($modeSupportProvider, $channelLookup, $channelServiceActions);
        $subscriber->onChannelDrop(new ChannelDropEvent(42, '#test', '#test', 'manual', new DateTimeImmutable('2026-01-02 03:04:05')));
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

        $subscriber = $this->createSubscriber($modeSupportProvider, $channelLookup, $channelServiceActions);
        $subscriber->onChannelDrop(new ChannelDropEvent(42, '#test', '#test', 'manual', new DateTimeImmutable('2026-01-02 03:04:05')));
    }

    #[Test]
    public function onChannelDropDoesNothingWhenChannelNotOnNetwork(): void
    {
        $modeSupportProvider = $this->createStub(ActiveChannelModeSupportProviderInterface::class);
        $channelLookup = $this->createMock(ChannelLookupPort::class);
        $channelLookup->expects(self::once())->method('findByChannelName')->with('#test')->willReturn(null);

        $channelServiceActions = $this->createMock(ChannelServiceActionsPort::class);
        $channelServiceActions->expects(self::never())->method('setChannelModes');

        $subscriber = $this->createSubscriber($modeSupportProvider, $channelLookup, $channelServiceActions);
        $subscriber->onChannelDrop(new ChannelDropEvent(42, '#test', '#test', 'manual', new DateTimeImmutable('2026-01-02 03:04:05')));
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

        $subscriber = $this->createSubscriber($modeSupportProvider, $channelLookup, $channelServiceActions);
        $subscriber->onChannelDrop(new ChannelDropEvent(42, '#test', '#test', 'manual', new DateTimeImmutable('2026-01-02 03:04:05')));
    }

    private function createSubscriber(
        ActiveChannelModeSupportProviderInterface $modeSupportProvider,
        ChannelLookupPort $channelLookup,
        ChannelServiceActionsPort $channelServiceActions,
    ): ChanServPermanentChannelSubscriber {
        $networkActions = new IrcChannelRegistrationNetworkActions(
            $modeSupportProvider,
            $channelLookup,
            $channelServiceActions,
        );
        $registrationLifecycle = new ChannelRegistrationService(
            $this->createStub(RegisteredChannelRepositoryInterface::class),
            $networkActions,
        );

        return new ChanServPermanentChannelSubscriber($registrationLifecycle);
    }
}
