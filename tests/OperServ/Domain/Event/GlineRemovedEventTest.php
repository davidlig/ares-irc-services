<?php

declare(strict_types=1);

namespace App\Tests\OperServ\Domain\Event;

use App\OperServ\Domain\Event\GlineRemovedEvent;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(GlineRemovedEvent::class)]
final class GlineRemovedEventTest extends TestCase
{
    #[Test]
    public function eventHoldsRemovalDetails(): void
    {
        $occurredAt = new DateTimeImmutable('2026-08-30 10:00:00');
        $event = new GlineRemovedEvent(7, '*@bad.example', 'ares-services.davidlig.net', 'expired', $occurredAt);

        self::assertSame(7, $event->glineId);
        self::assertSame('*@bad.example', $event->mask);
        self::assertSame('ares-services.davidlig.net', $event->removedBy);
        self::assertSame('expired', $event->cause);
        self::assertSame($occurredAt, $event->occurredAt);
    }
}
