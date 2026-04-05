<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\IRC\Subscriber;

use App\Application\Port\ServiceDebugNotifierInterface;
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
    public function onBurstCompleteCallsEnsureChannelJoinedOnAllNotifiers(): void
    {
        $notifier1 = $this->createMock(ServiceDebugNotifierInterface::class);
        $notifier1->expects(self::once())->method('ensureChannelJoined');

        $notifier2 = $this->createMock(ServiceDebugNotifierInterface::class);
        $notifier2->expects(self::once())->method('ensureChannelJoined');

        $subscriber = new DebugChannelJoinSubscriber([$notifier1, $notifier2]);

        $connection = $this->createStub(\App\Domain\IRC\Connection\ConnectionInterface::class);
        $event = new NetworkBurstCompleteEvent($connection, '001');
        $subscriber->onBurstComplete($event);
    }

    #[Test]
    public function onBurstCompleteHandlesEmptyNotifiersArray(): void
    {
        $subscriber = new DebugChannelJoinSubscriber([]);

        $connection = $this->createStub(\App\Domain\IRC\Connection\ConnectionInterface::class);
        $event = new NetworkBurstCompleteEvent($connection, '001');

        $subscriber->onBurstComplete($event);

        self::assertTrue(true);
    }
}
