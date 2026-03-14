<?php

declare(strict_types=1);

namespace App\Tests\Domain\IRC\Event;

use App\Domain\IRC\Event\ChannelModesChangedEvent;
use App\Domain\IRC\Network\Channel;
use App\Domain\IRC\ValueObject\ChannelName;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ChannelModesChangedEvent::class)]
final class ChannelModesChangedEventTest extends TestCase
{
    #[Test]
    public function constructionAndProperties(): void
    {
        $channel = new Channel(new ChannelName('#test'));
        $event = new ChannelModesChangedEvent($channel);

        self::assertSame($channel, $event->channel);
    }
}
