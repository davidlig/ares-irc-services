<?php

declare(strict_types=1);

namespace App\Tests\Irc\Adapter\Network;

use App\Irc\Adapter\Network\ChannelSyncCompletedMarkerSubscriber;
use App\Irc\Domain\Event\ChannelSyncedEvent;
use App\Irc\Domain\Network\Channel;
use App\Irc\Domain\ValueObject\ChannelName;
use App\Shared\Application\Port\ChannelSyncCompletedRegistryInterface;
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
        $this->registry->expects(self::never())->method('markSyncCompleted');

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
    public function onChannelSyncedMarksChannelAsCompletedWhenSetupNotApplicable(): void
    {
        $channel = new Channel(new ChannelName('#test'));

        $event = new ChannelSyncedEvent($channel, false);

        $this->registry->expects(self::once())
            ->method('markSyncCompleted')
            ->with('#test');

        $this->subscriber->onChannelSynced($event);
    }
}
