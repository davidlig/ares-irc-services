<?php

declare(strict_types=1);

namespace App\Tests\Domain\ChanServ\Event;

use App\Domain\ChanServ\Event\ChannelForbiddenEvent;
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
    public function occurredAtDefaultsToNow(): void
    {
        $before = new DateTimeImmutable('-1 second');
        $event = new ChannelForbiddenEvent(
            channelId: 1,
            channelName: '#Test',
            channelNameLower: '#test',
            reason: 'test',
            performedBy: 'Oper',
        );
        $after = new DateTimeImmutable('+1 second');

        self::assertGreaterThanOrEqual($before, $event->occurredAt);
        self::assertLessThanOrEqual($after, $event->occurredAt);
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
