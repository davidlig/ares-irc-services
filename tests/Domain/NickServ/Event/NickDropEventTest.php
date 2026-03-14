<?php

declare(strict_types=1);

namespace App\Tests\Domain\NickServ\Event;

use App\Domain\NickServ\Event\NickDropEvent;
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
        $event = new NickDropEvent(1, 'TestNick', 'testnick', 'inactivity');

        self::assertSame(1, $event->nickId);
        self::assertSame('TestNick', $event->nickname);
        self::assertSame('testnick', $event->nicknameLower);
        self::assertSame('inactivity', $event->reason);
        self::assertNotNull($event->occurredAt);
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
