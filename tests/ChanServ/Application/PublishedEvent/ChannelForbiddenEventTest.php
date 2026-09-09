<?php

declare(strict_types=1);

namespace App\Tests\ChanServ\Application\PublishedEvent;

use App\ChanServ\Application\PublishedEvent\ChannelForbiddenEvent;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ChannelForbiddenEvent::class)]
final class ChannelForbiddenEventTest extends TestCase
{
    #[Test]
    public function constructionWithAllProperties(): void
    {
        $occurredAt = new DateTimeImmutable('2025-06-15 12:00:00');
        $event = new ChannelForbiddenEvent(
            channelId: 42,
            channelName: '#Test',
            channelNameLower: '#test',
            reason: 'Abuse',
            performedBy: 'OperUser',
            occurredAt: $occurredAt,
        );

        self::assertSame(42, $event->channelId);
        self::assertSame('#Test', $event->channelName);
        self::assertSame('#test', $event->channelNameLower);
        self::assertSame('Abuse', $event->reason);
        self::assertSame('OperUser', $event->performedBy);
        self::assertSame($occurredAt, $event->occurredAt);
    }

    #[Test]
    public function requiresAnExplicitOccurrenceTime(): void
    {
        $occurredAt = new DateTimeImmutable('2026-01-02 03:04:05');
        $event = new ChannelForbiddenEvent(
            channelId: 1,
            channelName: '#Test',
            channelNameLower: '#test',
            reason: 'test',
            performedBy: 'Oper',
            occurredAt: $occurredAt,
        );

        self::assertSame($occurredAt, $event->occurredAt);
    }

    #[Test]
    public function occurredAtCanBeSetExplicitly(): void
    {
        $occurredAt = new DateTimeImmutable('2025-01-01 00:00:00');
        $event = new ChannelForbiddenEvent(
            channelId: 5,
            channelName: '#Channel',
            channelNameLower: '#channel',
            reason: 'Spam',
            performedBy: 'Admin',
            occurredAt: $occurredAt,
        );

        self::assertSame($occurredAt, $event->occurredAt);
    }
}
