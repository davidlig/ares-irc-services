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
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

#[CoversClass(ChanServPermanentChannelSubscriber::class)]
final class ChanServPermanentChannelSubscriberTest extends TestCase
{
    private ActiveChannelModeSupportProviderInterface&MockObject $modeSupportProvider;

    private ChannelLookupPort&MockObject $channelLookup;

    private ChannelServiceActionsPort&MockObject $channelServiceActions;

    private ChannelModeSupportInterface&MockObject $modeSupport;

    private LoggerInterface&MockObject $logger;

    private ChanServPermanentChannelSubscriber $subscriber;

    protected function setUp(): void
    {
        $this->modeSupportProvider = $this->createMock(ActiveChannelModeSupportProviderInterface::class);
        $this->channelLookup = $this->createMock(ChannelLookupPort::class);
        $this->channelServiceActions = $this->createMock(ChannelServiceActionsPort::class);
        $this->modeSupport = $this->createMock(ChannelModeSupportInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->subscriber = new ChanServPermanentChannelSubscriber(
            $this->modeSupportProvider,
            $this->channelLookup,
            $this->channelServiceActions,
            $this->logger,
        );
    }

    #[Test]
    public function subscribesToCorrectEvents(): void
    {
        // This is a static test - no instance needed
        self::assertSame(
            [
                ChannelRegisteredEvent::class => ['onChannelRegistered', 0],
                ChannelDropEvent::class => ['onChannelDrop', 0],
            ],
            ChanServPermanentChannelSubscriber::getSubscribedEvents(),
        );
    }

    #[Test]
    public function onChannelRegisteredSetsPermanentModeWhenSupported(): void
    {
        $event = new ChannelRegisteredEvent(42, '#test', '#test');

        $this->modeSupportProvider
            ->expects(self::once())
            ->method('getSupport')
            ->willReturn($this->modeSupport);

        $this->modeSupport
            ->expects(self::once())
            ->method('hasPermanentChannelMode')
            ->willReturn(true);

        $this->channelLookup
            ->expects(self::once())
            ->method('findByChannelName')
            ->with('#test')
            ->willReturn(new ChannelView('#test', '+nt', null, 1));

        $this->channelServiceActions
            ->expects(self::once())
            ->method('setChannelModes')
            ->with('#test', '+P', []);

        $this->logger
            ->expects(self::once())
            ->method('debug')
            ->with('ChanServ set +P (permanent) on channel registration', ['channel' => '#test']);

        $this->subscriber->onChannelRegistered($event);
    }

    #[Test]
    public function onChannelRegisteredDoesNothingWhenModeNotSupported(): void
    {
        $event = new ChannelRegisteredEvent(42, '#test', '#test');

        $this->modeSupportProvider
            ->expects(self::once())
            ->method('getSupport')
            ->willReturn($this->modeSupport);

        $this->modeSupport
            ->expects(self::once())
            ->method('hasPermanentChannelMode')
            ->willReturn(false);

        $this->channelLookup->expects(self::never())->method('findByChannelName');
        $this->channelServiceActions->expects(self::never())->method('setChannelModes');
        $this->logger->expects(self::never())->method('debug');

        $this->subscriber->onChannelRegistered($event);
    }

    #[Test]
    public function onChannelRegisteredDoesNothingWhenChannelNotOnNetwork(): void
    {
        $event = new ChannelRegisteredEvent(42, '#test', '#test');

        $this->modeSupportProvider
            ->expects(self::once())
            ->method('getSupport')
            ->willReturn($this->modeSupport);

        $this->modeSupport
            ->expects(self::once())
            ->method('hasPermanentChannelMode')
            ->willReturn(true);

        $this->channelLookup
            ->expects(self::once())
            ->method('findByChannelName')
            ->with('#test')
            ->willReturn(null);

        $this->channelServiceActions->expects(self::never())->method('setChannelModes');
        $this->logger->expects(self::never())->method('debug');

        $this->subscriber->onChannelRegistered($event);
    }

    #[Test]
    public function onChannelDropRemovesPermanentModeWhenPresent(): void
    {
        $event = new ChannelDropEvent(42, '#test', '#test', 'inactivity');

        $this->modeSupportProvider
            ->expects(self::once())
            ->method('getSupport')
            ->willReturn($this->modeSupport);

        $this->modeSupport
            ->expects(self::once())
            ->method('hasPermanentChannelMode')
            ->willReturn(true);

        $this->channelLookup
            ->expects(self::once())
            ->method('findByChannelName')
            ->with('#test')
            ->willReturn(new ChannelView('#test', '+ntP', null, 1));

        $this->channelServiceActions
            ->expects(self::once())
            ->method('setChannelModes')
            ->with('#test', '-P', []);

        $this->logger
            ->expects(self::once())
            ->method('debug')
            ->with('ChanServ removed -P (permanent) on channel drop', [
                'channel' => '#test',
                'reason' => 'inactivity',
            ]);

        $this->subscriber->onChannelDrop($event);
    }

    #[Test]
    public function onChannelDropDoesNothingWhenModeNotSupported(): void
    {
        $event = new ChannelDropEvent(42, '#test', '#test', 'manual');

        $this->modeSupportProvider
            ->expects(self::once())
            ->method('getSupport')
            ->willReturn($this->modeSupport);

        $this->modeSupport
            ->expects(self::once())
            ->method('hasPermanentChannelMode')
            ->willReturn(false);

        $this->channelLookup->expects(self::never())->method('findByChannelName');
        $this->channelServiceActions->expects(self::never())->method('setChannelModes');
        $this->logger->expects(self::never())->method('debug');

        $this->subscriber->onChannelDrop($event);
    }

    #[Test]
    public function onChannelDropDoesNothingWhenChannelNotOnNetwork(): void
    {
        $event = new ChannelDropEvent(42, '#test', '#test', 'manual');

        $this->modeSupportProvider
            ->expects(self::once())
            ->method('getSupport')
            ->willReturn($this->modeSupport);

        $this->modeSupport
            ->expects(self::once())
            ->method('hasPermanentChannelMode')
            ->willReturn(true);

        $this->channelLookup
            ->expects(self::once())
            ->method('findByChannelName')
            ->with('#test')
            ->willReturn(null);

        $this->channelServiceActions->expects(self::never())->method('setChannelModes');
        $this->logger->expects(self::never())->method('debug');

        $this->subscriber->onChannelDrop($event);
    }

    #[Test]
    public function onChannelDropDoesNothingWhenChannelDoesNotHavePermanentMode(): void
    {
        $event = new ChannelDropEvent(42, '#test', '#test', 'manual');

        $this->modeSupportProvider
            ->expects(self::once())
            ->method('getSupport')
            ->willReturn($this->modeSupport);

        $this->modeSupport
            ->expects(self::once())
            ->method('hasPermanentChannelMode')
            ->willReturn(true);

        $this->channelLookup
            ->expects(self::once())
            ->method('findByChannelName')
            ->with('#test')
            ->willReturn(new ChannelView('#test', '+nt', null, 1));

        $this->channelServiceActions->expects(self::never())->method('setChannelModes');
        $this->logger->expects(self::never())->method('debug');

        $this->subscriber->onChannelDrop($event);
    }

    #[Test]
    public function onChannelDropRemovesModeWhenLowercasePInModes(): void
    {
        $event = new ChannelDropEvent(42, '#Test', '#test', 'manual');

        $this->modeSupportProvider
            ->expects(self::once())
            ->method('getSupport')
            ->willReturn($this->modeSupport);

        $this->modeSupport
            ->expects(self::once())
            ->method('hasPermanentChannelMode')
            ->willReturn(true);

        $this->channelLookup
            ->expects(self::once())
            ->method('findByChannelName')
            ->with('#Test')
            ->willReturn(new ChannelView('#Test', '+ntP', null, 1));

        $this->channelServiceActions
            ->expects(self::once())
            ->method('setChannelModes')
            ->with('#Test', '-P', []);

        $this->logger
            ->expects(self::once())
            ->method('debug');

        $this->subscriber->onChannelDrop($event);
    }
}
