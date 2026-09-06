<?php

declare(strict_types=1);

namespace App\Tests\Irc\Domain\Event;

use App\Irc\Domain\Event\ChannelTopicChangedEvent;
use App\Irc\Domain\Network\Channel;
use App\Irc\Domain\ValueObject\ChannelName;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ChannelTopicChangedEvent::class)]
final class ChannelTopicChangedEventTest extends TestCase
{
    #[Test]
    public function constructionAndProperties(): void
    {
        $channel = new Channel(new ChannelName('#test'));
        $event = new ChannelTopicChangedEvent($channel);

        self::assertSame($channel, $event->channel);
    }
}
