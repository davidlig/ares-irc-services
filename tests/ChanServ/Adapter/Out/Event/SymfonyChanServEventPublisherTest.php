<?php

declare(strict_types=1);

namespace App\Tests\ChanServ\Adapter\Out\Event;

use App\ChanServ\Adapter\Out\Event\SymfonyChanServEventPublisher;
use App\ChanServ\Application\Port\In\ChanServEvent;
use App\ChanServ\Application\PublishedEvent\ChannelDropEvent;
use App\Shared\Application\Port\EventBusInterface;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use stdClass;
use TypeError;

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

    #[Test]
    public function acceptsContextEventsWithoutAConcreteTypeUnion(): void
    {
        $event = new class implements ChanServEvent {};
        $bus = $this->createMock(EventBusInterface::class);
        $bus->expects(self::once())->method('dispatch')->with(self::identicalTo($event));
        new SymfonyChanServEventPublisher($bus)->publish($event);
    }

    #[Test]
    public function rejectsEventsFromOutsideChanServ(): void
    {
        $bus = $this->createMock(EventBusInterface::class);
        $bus->expects(self::never())->method('dispatch');
        $this->expectException(TypeError::class);
        new ReflectionMethod(SymfonyChanServEventPublisher::class, 'publish')->invoke(new SymfonyChanServEventPublisher($bus), new stdClass());
    }
}
