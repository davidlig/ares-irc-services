<?php

declare(strict_types=1);

namespace App\Tests\Domain\ChanServ\Event;

use App\Domain\ChanServ\Event\ChannelDropEvent;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ChannelDropEvent::class)]
final class ChannelDropEventTest extends TestCase
{
    #[Test]
    public function constructionAndProperties(): void
    {
        $at = new DateTimeImmutable('2024-01-15 12:00:00');
        $event = new ChannelDropEvent(1, '#test', '#test', 'inactivity', $at);

        self::assertSame(1, $event->channelId);
        self::assertSame('#test', $event->channelName);
        self::assertSame('#test', $event->channelNameLower);
        self::assertSame('inactivity', $event->reason);
        self::assertSame($at, $event->occurredAt);
    }
}
