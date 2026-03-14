<?php

declare(strict_types=1);

namespace App\Tests\Domain\IRC\Event;

use App\Domain\IRC\Event\PartReceivedEvent;
use App\Domain\IRC\ValueObject\ChannelName;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(PartReceivedEvent::class)]
final class PartReceivedEventTest extends TestCase
{
    #[Test]
    public function constructionAndProperties(): void
    {
        $channelName = new ChannelName('#test');
        $event = new PartReceivedEvent('UID123', $channelName, 'leaving');

        self::assertSame('UID123', $event->sourceId);
        self::assertSame($channelName, $event->channelName);
        self::assertSame('leaving', $event->reason);
        self::assertFalse($event->wasKicked);
    }

    #[Test]
    public function wasKickedCanBeTrue(): void
    {
        $channelName = new ChannelName('#test');
        $event = new PartReceivedEvent('UID123', $channelName, 'kicked', true);

        self::assertTrue($event->wasKicked);
    }
}
