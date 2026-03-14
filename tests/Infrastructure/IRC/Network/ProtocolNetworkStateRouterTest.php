<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\IRC\Network;

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
        $router = new ProtocolNetworkStateRouter('unreal', []);
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
        $router = new ProtocolNetworkStateRouter('unreal', ['unreal' => $adapter]);
        $router->onMessageReceived($event);
    }

    #[Test]
    public function onMessageReceivedDoesNothingWhenProtocolNotInAdaptors(): void
    {
        $message = new IRCMessage('PING', '', [], '', MessageDirection::Incoming);
        $event = new MessageReceivedEvent($message);
        $adapter = $this->createMock(NetworkStateAdapterInterface::class);
        $adapter->expects(self::never())->method('handleMessage');
        $router = new ProtocolNetworkStateRouter('unknown', ['unreal' => $adapter]);
        $router->onMessageReceived($event);
    }
}
