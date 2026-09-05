<?php

declare(strict_types=1);

namespace App\Tests\Domain\ChanServ\Event;

use App\Domain\ChanServ\Event\ChannelDropCleanupEvent;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ChannelDropCleanupEvent::class)]
final class ChannelDropCleanupEventTest extends TestCase
{
    #[Test]
    public function exposesDropDataWithExplicitOccurrenceTime(): void
    {
        $occurredAt = new DateTimeImmutable('2026-09-05 12:00:00');

        $event = new ChannelDropCleanupEvent(42, '#Test', '#test', 'manual', $occurredAt);

        self::assertSame(42, $event->channelId);
        self::assertSame('#Test', $event->channelName);
        self::assertSame('#test', $event->channelNameLower);
        self::assertSame('manual', $event->reason);
        self::assertSame($occurredAt, $event->occurredAt);
    }

    #[Test]
    public function createsOccurrenceTimeByDefault(): void
    {
        $before = new DateTimeImmutable();

        $event = new ChannelDropCleanupEvent(7, '#Other', '#other', 'inactivity');

        self::assertGreaterThanOrEqual($before, $event->occurredAt);
        self::assertLessThanOrEqual(new DateTimeImmutable(), $event->occurredAt);
    }
}
