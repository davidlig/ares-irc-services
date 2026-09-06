<?php

declare(strict_types=1);

namespace App\Tests\Irc\Adapter\Network;

use App\Irc\Adapter\Event\MessageReceivedEvent;
use App\Irc\Adapter\Network\ProtocolNetworkStateRouter;
use App\Irc\Adapter\Protocol\IRCMessage;
use App\Irc\Adapter\Protocol\MessageDirection;
use App\Irc\Adapter\Protocol\NetworkStateAdapterInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ProtocolNetworkStateRouter::class)]
final class ProtocolNetworkStateRouterTest extends TestCase
{
    #[Test]
    public function getSubscribedEventsReturnsMessageReceivedAtPriorityZero(): void
    {
        $router = new ProtocolNetworkStateRouter($this->createStub(NetworkStateAdapterInterface::class));
        $events = $router->getSubscribedEvents();
        self::assertArrayHasKey(MessageReceivedEvent::class, $events);
        self::assertSame(['onMessageReceived', 0], $events[MessageReceivedEvent::class]);
    }

    #[Test]
    public function onMessageReceivedDelegatesToSelectedAdapter(): void
    {
        $message = new IRCMessage('PING', '', [], '', MessageDirection::Incoming);
        $event = new MessageReceivedEvent($message);
        $adapter = $this->createMock(NetworkStateAdapterInterface::class);
        $adapter->expects(self::once())
            ->method('handleMessage')
            ->with($message);
        $router = new ProtocolNetworkStateRouter($adapter);
        $router->onMessageReceived($event);
    }
}
