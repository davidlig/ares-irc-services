<?php

declare(strict_types=1);

namespace App\Tests\Domain\NickServ\Event;

use App\Domain\NickServ\Event\NickDropCleanupEvent;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(NickDropCleanupEvent::class)]
final class NickDropCleanupEventTest extends TestCase
{
    #[Test]
    public function exposesDropDataWithExplicitOccurrenceTime(): void
    {
        $occurredAt = new DateTimeImmutable('2026-09-05 12:00:00');

        $event = new NickDropCleanupEvent(42, 'TestNick', 'testnick', 'manual', $occurredAt);

        self::assertSame(42, $event->nickId);
        self::assertSame('TestNick', $event->nickname);
        self::assertSame('testnick', $event->nicknameLower);
        self::assertSame('manual', $event->reason);
        self::assertSame($occurredAt, $event->occurredAt);
    }

    #[Test]
    public function createsOccurrenceTimeByDefault(): void
    {
        $before = new DateTimeImmutable();

        $event = new NickDropCleanupEvent(7, 'OtherNick', 'othernick', 'inactivity');

        self::assertGreaterThanOrEqual($before, $event->occurredAt);
        self::assertLessThanOrEqual(new DateTimeImmutable(), $event->occurredAt);
    }
}
