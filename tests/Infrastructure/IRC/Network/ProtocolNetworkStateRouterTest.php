<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\IRC\Network;

use App\Application\Port\ActiveConnectionHolderInterface;
use App\Application\Port\ProtocolModuleInterface;
use App\Domain\IRC\Event\MessageReceivedEvent;
use App\Domain\IRC\Message\IRCMessage;
use App\Domain\IRC\Message\MessageDirection;
use App\Domain\IRC\Network\NetworkStateAdapterInterface;
use App\Infrastructure\IRC\Network\ProtocolNetworkStateRouter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ProtocolNetworkStateRouter::class)]
final class ProtocolNetworkStateRouterTest extends TestCase
{
    #[Test]
    public function getSubscribedEventsReturnsMessageReceivedAtPriorityZero(): void
    {
        $router = new ProtocolNetworkStateRouter($this->createStub(ActiveConnectionHolderInterface::class), []);
        $events = $router->getSubscribedEvents();
        self::assertArrayHasKey(MessageReceivedEvent::class, $events);
        self::assertSame(['onMessageReceived', 0], $events[MessageReceivedEvent::class]);
    }

    #[Test]
    public function onMessageReceivedDelegatesToAdapterWhenProtocolConfigured(): void
    {
        $message = new IRCMessage('PING', '', [], '', MessageDirection::Incoming);
        $event = new MessageReceivedEvent($message);
        $adapter = $this->createMock(NetworkStateAdapterInterface::class);
        $adapter->expects(self::once())
            ->method('handleMessage')
            ->with($message);
        $router = new ProtocolNetworkStateRouter($this->createConnectionHolderForProtocol('unreal'), ['unreal' => $adapter]);
        $router->onMessageReceived($event);
    }

    #[Test]
    public function onMessageReceivedDoesNothingWhenProtocolNotInAdaptors(): void
    {
        $message = new IRCMessage('PING', '', [], '', MessageDirection::Incoming);
        $event = new MessageReceivedEvent($message);
        $adapter = $this->createMock(NetworkStateAdapterInterface::class);
        $adapter->expects(self::never())->method('handleMessage');
        $router = new ProtocolNetworkStateRouter($this->createConnectionHolderForProtocol('unknown'), ['unreal' => $adapter]);
        $router->onMessageReceived($event);
    }

    #[Test]
    public function onMessageReceivedDoesNothingWhenNoActiveProtocol(): void
    {
        $message = new IRCMessage('PING', '', [], '', MessageDirection::Incoming);
        $event = new MessageReceivedEvent($message);
        $adapter = $this->createMock(NetworkStateAdapterInterface::class);
        $adapter->expects(self::never())->method('handleMessage');
        $router = new ProtocolNetworkStateRouter($this->createStub(ActiveConnectionHolderInterface::class), ['unreal' => $adapter]);
        $router->onMessageReceived($event);
    }

    private function createConnectionHolderForProtocol(string $protocol): ActiveConnectionHolderInterface
    {
        $module = $this->createStub(ProtocolModuleInterface::class);
        $module->method('getProtocolName')->willReturn($protocol);

        $holder = $this->createStub(ActiveConnectionHolderInterface::class);
        $holder->method('getProtocolModule')->willReturn($module);

        return $holder;
    }
}
