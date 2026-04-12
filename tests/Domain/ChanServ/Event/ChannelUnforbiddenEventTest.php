<?php

declare(strict_types=1);

namespace App\Tests\Domain\ChanServ\Event;

use App\Domain\ChanServ\Event\ChannelUnforbiddenEvent;
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
    public function occurredAtDefaultsToNow(): void
    {
        $before = new DateTimeImmutable('-1 second');
        $event = new ChannelUnforbiddenEvent(
            channelName: '#Test',
            channelNameLower: '#test',
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
        $event = new ChannelUnforbiddenEvent(
            channelName: '#Channel',
            channelNameLower: '#channel',
            performedBy: 'Admin',
            occurredAt: $occurredAt,
        );

        self::assertSame($occurredAt, $event->occurredAt);
    }
}
