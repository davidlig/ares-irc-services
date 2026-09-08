<?php

declare(strict_types=1);

namespace App\Tests\ChanServ\Adapter\Out\Event;

use App\ChanServ\Adapter\Out\Event\SymfonyChanServEventPublisher;
use App\ChanServ\Application\PublishedEvent\ChannelDropEvent;
use App\Shared\Application\Port\EventBusInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(SymfonyChanServEventPublisher::class)]
final class SymfonyChanServEventPublisherTest extends TestCase
{
    #[Test]
    public function forwardsPublishedEventsToTheBus(): void
    {
        $event = new ChannelDropEvent(42, '#test', '#test', 'manual');
        $eventBus = $this->createMock(EventBusInterface::class);
        $eventBus->expects(self::once())->method('dispatch')->with($event);

        new SymfonyChanServEventPublisher($eventBus)->publish($event);
    }
}
