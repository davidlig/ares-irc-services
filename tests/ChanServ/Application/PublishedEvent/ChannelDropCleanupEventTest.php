<?php

declare(strict_types=1);

namespace App\Tests\ChanServ\Application\PublishedEvent;

use App\ChanServ\Application\PublishedEvent\ChannelDropCleanupEvent;
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

        $event = new ChannelDropCleanupEvent(
            channelId: 42,
            occurredAt: $occurredAt,
            channelName: '#Test',
            channelNameLower: '#test',
            reason: 'manual',
        );

        self::assertSame(42, $event->channelId);
        self::assertSame('#Test', $event->channelName);
        self::assertSame('#test', $event->channelNameLower);
        self::assertSame('manual', $event->reason);
        self::assertSame($occurredAt, $event->occurredAt);
    }

    #[Test]
    public function supportsCleanupWithOnlyIdentityAndOccurrenceTime(): void
    {
        $occurredAt = new DateTimeImmutable('2026-09-05 13:00:00');

        $event = new ChannelDropCleanupEvent(7, $occurredAt);

        self::assertSame($occurredAt, $event->occurredAt);
        self::assertSame('', $event->channelName);
        self::assertSame('', $event->channelNameLower);
        self::assertSame('', $event->reason);
    }
}
