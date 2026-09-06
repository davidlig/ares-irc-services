<?php

declare(strict_types=1);

namespace App\Tests\NickServ\Domain\Event;

use App\NickServ\Domain\Event\NickDropEvent;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(NickDropEvent::class)]
final class NickDropEventTest extends TestCase
{
    #[Test]
    public function constructionAndProperties(): void
    {
        $before = new DateTimeImmutable();
        $event = new NickDropEvent(1, 'TestNick', 'testnick', 'inactivity');
        $after = new DateTimeImmutable();

        self::assertSame(1, $event->nickId);
        self::assertSame('TestNick', $event->nickname);
        self::assertSame('testnick', $event->nicknameLower);
        self::assertSame('inactivity', $event->reason);
        self::assertGreaterThanOrEqual($before, $event->occurredAt);
        self::assertLessThanOrEqual($after, $event->occurredAt);
    }

    #[Test]
    public function constructionWithExplicitOccurredAt(): void
    {
        $occurredAt = new DateTimeImmutable('2025-01-15 12:00:00');

        $event = new NickDropEvent(2, 'Other', 'other', 'manual', $occurredAt);

        self::assertSame(2, $event->nickId);
        self::assertSame('manual', $event->reason);
        self::assertSame($occurredAt, $event->occurredAt);
    }
}
