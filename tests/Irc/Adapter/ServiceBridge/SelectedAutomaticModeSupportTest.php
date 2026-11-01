<?php

declare(strict_types=1);

namespace App\Tests\Irc\Adapter\ServiceBridge;

use App\Irc\Adapter\Event\NetworkBurstCompleteEvent;
use App\Irc\Adapter\Out\Connection\ActiveConnectionHolder;
use App\Irc\Adapter\Out\Connection\ConnectionInterface;
use App\Irc\Adapter\Protocol\ProtocolHandlerInterface;
use App\Irc\Adapter\Runtime\ProtocolRuntimeModuleInterface;
use App\Irc\Adapter\ServiceBridge\SelectedAutomaticModeSupport;
use App\Irc\Application\Port\In\AutomaticModeSupport;
use App\Irc\Application\Port\In\LocalUserModeSyncPort;
use App\Irc\Application\Port\In\NativeAutomaticModeSupport;
use App\Irc\Application\Port\In\ProtocolServiceActionsInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use RuntimeException;

#[CoversClass(SelectedAutomaticModeSupport::class)]
final class SelectedAutomaticModeSupportTest extends TestCase
{
    public function testSuccessfulWritesSynchronizeLocalStateWithoutAnIrcdEcho(): void
    {
        $holder = $this->holder($this->createStub(ProtocolHandlerInterface::class));
        $sync = $this->createMock(LocalUserModeSyncPort::class);
        $sync->expects(self::once())->method('apply')->with('UID', '+B');
        $bridge = new SelectedAutomaticModeSupport($holder, new NullLogger(), $sync);
        self::assertTrue($bridge->changeUserModes('UID', '+B'));
        self::assertFalse($bridge->usesNativeProfiles());
        self::assertNull($bridge->getAutomaticModes());
    }

    public function testUnknownDisconnectedNativeAndSupportedCapabilitiesAreDistinct(): void
    {
        $sync = $this->createMock(LocalUserModeSyncPort::class);
        $sync->expects(self::never())->method('apply');
        $bridge = new SelectedAutomaticModeSupport(new ActiveConnectionHolder(), new NullLogger(), $sync);
        self::assertNull($bridge->getAutomaticModes());
        self::assertFalse($bridge->changeUserModes('UID', '+B'));
        $handler = $this->createStubForIntersectionOfInterfaces([ProtocolHandlerInterface::class, NativeAutomaticModeSupport::class]);
        self::assertInstanceOf(ProtocolHandlerInterface::class, $handler);
        self::assertInstanceOf(NativeAutomaticModeSupport::class, $handler);
        $handler->method('getAutomaticModes')->willReturn(['bot' => 'B']);
        $bridge = new SelectedAutomaticModeSupport($this->holder($handler), new NullLogger(), $sync);
        self::assertTrue($bridge->usesNativeProfiles());
        self::assertSame(['bot' => 'B'], $bridge->getAutomaticModes());
        self::assertFalse($bridge->changeUserModes('UID', '+B'));
        $supported = $this->createStubForIntersectionOfInterfaces([ProtocolHandlerInterface::class, AutomaticModeSupport::class]);
        self::assertInstanceOf(ProtocolHandlerInterface::class, $supported);
        self::assertInstanceOf(AutomaticModeSupport::class, $supported);
        $supported->method('getAutomaticModes')->willReturn([]);
        $bridge = new SelectedAutomaticModeSupport($this->holder($supported), new NullLogger(), $sync);
        self::assertSame([], $bridge->getAutomaticModes());
    }

    public function testDeliveryFailureDoesNotSynchronizeOrThrowIntoOtherSavedEventListeners(): void
    {
        $actions = $this->createMock(ProtocolServiceActionsInterface::class);
        $actions->expects(self::once())->method('setUserMode')->willThrowException(new RuntimeException('offline'));
        $holder = $this->holder($this->createStub(ProtocolHandlerInterface::class), $actions);
        $sync = $this->createMock(LocalUserModeSyncPort::class);
        $sync->expects(self::never())->method('apply');
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('warning');
        self::assertFalse(new SelectedAutomaticModeSupport($holder, $logger, $sync)->changeUserModes('UID', '+B'));
    }

    private function holder(ProtocolHandlerInterface $handler, ?ProtocolServiceActionsInterface $actions = null): ActiveConnectionHolder
    {
        $module = $this->createStub(ProtocolRuntimeModuleInterface::class);
        $module->method('getHandler')->willReturn($handler);
        $module->method('getServiceActions')->willReturn($actions ?? $this->createStub(ProtocolServiceActionsInterface::class));
        $connection = $this->createStub(ConnectionInterface::class);
        $connection->method('isConnected')->willReturn(true);
        $holder = new ActiveConnectionHolder();
        $holder->setProtocolModule($module);
        $holder->onBurstComplete(new NetworkBurstCompleteEvent($connection, '001'));

        return $holder;
    }
}
