<?php

declare(strict_types=1);

namespace App\Tests\ChanServ\Application\PublishedEvent;

use App\ChanServ\Application\PublishedEvent\ChannelRegisteredEvent;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ChannelRegisteredEvent::class)]
final class ChannelRegisteredEventTest extends TestCase
{
    #[Test]
    public function constructorSetsAllProperties(): void
    {
        $occurredAt = new DateTimeImmutable('2025-01-15 10:30:00');
        $event = new ChannelRegisteredEvent(42, '#test', '#test', $occurredAt);

        self::assertSame(42, $event->channelId);
        self::assertSame('#test', $event->channelName);
        self::assertSame('#test', $event->channelNameLower);
        self::assertSame($occurredAt, $event->occurredAt);
    }

    #[Test]
    public function constructorRequiresExplicitOccurredAt(): void
    {
        $occurredAt = new DateTimeImmutable('2026-01-02 03:04:05');
        $event = new ChannelRegisteredEvent(1, '#channel', '#channel', $occurredAt);

        self::assertSame($occurredAt, $event->occurredAt);
    }
}
