<?php

declare(strict_types=1);

namespace App\Tests\ChanServ\Application\PublishedEvent;

use App\ChanServ\Application\PublishedEvent\ChannelRestoredEvent;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ChannelRestoredEvent::class)]
final class ChannelRestoredEventTest extends TestCase
{
    #[Test]
    public function constructionAndProperties(): void
    {
        $at = new DateTimeImmutable('2026-09-19 13:36:00');
        $event = new ChannelRestoredEvent(7, '#Test2', '#test2', 'OperNick', $at);

        self::assertSame(7, $event->channelId);
        self::assertSame('#Test2', $event->channelName);
        self::assertSame('#test2', $event->channelNameLower);
        self::assertSame('OperNick', $event->performedBy);
        self::assertSame($at, $event->occurredAt);
    }

    #[Test]
    public function acceptsNullOperator(): void
    {
        $event = new ChannelRestoredEvent(7, '#Test2', '#test2', null, new DateTimeImmutable());

        self::assertNull($event->performedBy);
    }
}
