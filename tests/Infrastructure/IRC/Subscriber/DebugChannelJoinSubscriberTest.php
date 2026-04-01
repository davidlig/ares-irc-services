<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\IRC\Subscriber;

use App\Application\Port\DebugActionPort;
use App\Domain\IRC\Event\NetworkBurstCompleteEvent;
use App\Infrastructure\IRC\Subscriber\DebugChannelJoinSubscriber;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(DebugChannelJoinSubscriber::class)]
final class DebugChannelJoinSubscriberTest extends TestCase
{
    #[Test]
    public function getSubscribedEventsReturnsBurstComplete(): void
    {
        $events = DebugChannelJoinSubscriber::getSubscribedEvents();

        self::assertArrayHasKey(NetworkBurstCompleteEvent::class, $events);
        self::assertSame(['onBurstComplete', 0], $events[NetworkBurstCompleteEvent::class]);
    }

    #[Test]
    public function onBurstCompleteCallsEnsureChannelJoined(): void
    {
        $debugAction = $this->createMock(DebugActionPort::class);
        $debugAction->expects(self::once())->method('ensureChannelJoined');

        $subscriber = new DebugChannelJoinSubscriber($debugAction);

        $connection = $this->createStub(\App\Domain\IRC\Connection\ConnectionInterface::class);
        $event = new NetworkBurstCompleteEvent($connection, '001');
        $subscriber->onBurstComplete($event);
    }
}
