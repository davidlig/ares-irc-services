<?php

declare(strict_types=1);

namespace App\Tests\Domain\IRC\Event;

use App\Domain\IRC\Event\KickReceivedEvent;
use App\Domain\IRC\ValueObject\ChannelName;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(KickReceivedEvent::class)]
final class KickReceivedEventTest extends TestCase
{
    #[Test]
    public function constructionAndProperties(): void
    {
        $channelName = new ChannelName('#test');
        $event = new KickReceivedEvent($channelName, 'UID456', 'bye');

        self::assertSame($channelName, $event->channelName);
        self::assertSame('UID456', $event->targetId);
        self::assertSame('bye', $event->reason);
    }
}
