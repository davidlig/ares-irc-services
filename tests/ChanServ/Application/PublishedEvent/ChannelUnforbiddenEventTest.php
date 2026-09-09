<?php

declare(strict_types=1);

namespace App\Tests\ChanServ\Application\PublishedEvent;

use App\ChanServ\Application\PublishedEvent\ChannelUnforbiddenEvent;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ChannelUnforbiddenEvent::class)]
final class ChannelUnforbiddenEventTest extends TestCase
{
    #[Test]
    public function constructionWithAllProperties(): void
    {
        $occurredAt = new DateTimeImmutable('2025-06-15 12:00:00');
        $event = new ChannelUnforbiddenEvent(
            channelName: '#Test',
            channelNameLower: '#test',
            performedBy: 'OperUser',
            occurredAt: $occurredAt,
        );

        self::assertSame('#Test', $event->channelName);
        self::assertSame('#test', $event->channelNameLower);
        self::assertSame('OperUser', $event->performedBy);
        self::assertSame($occurredAt, $event->occurredAt);
    }

    #[Test]
    public function requiresAnExplicitOccurrenceTime(): void
    {
        $occurredAt = new DateTimeImmutable('2026-01-02 03:04:05');
        $event = new ChannelUnforbiddenEvent(
            channelName: '#Test',
            channelNameLower: '#test',
            performedBy: 'Oper',
            occurredAt: $occurredAt,
        );

        self::assertSame($occurredAt, $event->occurredAt);
    }

    #[Test]
    public function occurredAtCanBeSetExplicitly(): void
    {
        $occurredAt = new DateTimeImmutable('2025-01-01 00:00:00');
        $event = new ChannelUnforbiddenEvent(
            channelName: '#Channel',
            channelNameLower: '#channel',
            performedBy: 'Admin',
            occurredAt: $occurredAt,
        );

        self::assertSame($occurredAt, $event->occurredAt);
    }
}
