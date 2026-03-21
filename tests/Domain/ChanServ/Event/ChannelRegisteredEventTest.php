<?php

declare(strict_types=1);

namespace App\Tests\Domain\ChanServ\Event;

use App\Domain\ChanServ\Event\ChannelRegisteredEvent;
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
    public function constructorDefaultsOccurredAt(): void
    {
        $before = new DateTimeImmutable();
        $event = new ChannelRegisteredEvent(1, '#channel', '#channel');
        $after = new DateTimeImmutable();

        self::assertGreaterThanOrEqual($before, $event->occurredAt);
        self::assertLessThanOrEqual($after, $event->occurredAt);
    }
}
