<?php

declare(strict_types=1);

namespace App\Tests\ChanServ\Adapter\Out\Event;

use App\ChanServ\Adapter\Out\Event\SymfonyChanServEventPublisher;
use App\ChanServ\Application\PublishedEvent\ChannelDropEvent;
use App\Shared\Application\Port\EventBusInterface;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(SymfonyChanServEventPublisher::class)]
final class SymfonyChanServEventPublisherTest extends TestCase
{
    #[Test]
    public function forwardsPublishedEventsToTheBus(): void
    {
        $event = new ChannelDropEvent(42, '#test', '#test', 'manual', new DateTimeImmutable('2026-01-02 03:04:05'));
        $eventBus = $this->createMock(EventBusInterface::class);
        $eventBus->expects(self::once())->method('dispatch')->with($event);

        new SymfonyChanServEventPublisher($eventBus)->publish($event);
    }
}
