<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\IRC\Network;

use App\Application\Port\ChannelSyncCompletedRegistryInterface;
use App\Domain\IRC\Event\ChannelSyncedEvent;
use App\Domain\IRC\Network\Channel;
use App\Domain\IRC\ValueObject\ChannelName;
use App\Infrastructure\IRC\Network\ChannelSyncCompletedMarkerSubscriber;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[CoversClass(ChannelSyncCompletedMarkerSubscriber::class)]
final class ChannelSyncCompletedMarkerSubscriberTest extends TestCase
{
    private ChannelSyncCompletedRegistryInterface&MockObject $registry;

    private ChannelSyncCompletedMarkerSubscriber $subscriber;

    protected function setUp(): void
    {
        $this->registry = $this->createMock(ChannelSyncCompletedRegistryInterface::class);
        $this->subscriber = new ChannelSyncCompletedMarkerSubscriber($this->registry);
    }

    #[Test]
    public function getSubscribedEventsReturnsCorrectEvent(): void
    {
        $events = ChannelSyncCompletedMarkerSubscriber::getSubscribedEvents();

        self::assertArrayHasKey(ChannelSyncedEvent::class, $events);
        self::assertSame(['onChannelSynced', -100], $events[ChannelSyncedEvent::class]);
    }

    #[Test]
    public function onChannelSyncedMarksChannelAsCompletedWhenSetupApplicable(): void
    {
        $channel = new Channel(new ChannelName('#test'));

        $event = new ChannelSyncedEvent($channel, true);

        $this->registry->expects(self::once())
            ->method('markSyncCompleted')
            ->with('#test');

        $this->subscriber->onChannelSynced($event);
    }

    #[Test]
    public function onChannelSyncedDoesNotMarkWhenSetupNotApplicable(): void
    {
        $channel = new Channel(new ChannelName('#test'));

        $event = new ChannelSyncedEvent($channel, false);

        $this->registry->expects(self::never())
            ->method('markSyncCompleted');

        $this->subscriber->onChannelSynced($event);
    }
}
