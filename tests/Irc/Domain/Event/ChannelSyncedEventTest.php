<?php

declare(strict_types=1);

namespace App\Tests\Irc\Domain\Event;

use App\Irc\Domain\Event\ChannelSyncedEvent;
use App\Irc\Domain\Network\Channel;
use App\Irc\Domain\ValueObject\ChannelName;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ChannelSyncedEvent::class)]
final class ChannelSyncedEventTest extends TestCase
{
    #[Test]
    public function constructionAndProperties(): void
    {
        $channel = new Channel(new ChannelName('#test'), '', new DateTimeImmutable('@0'));
        $event = new ChannelSyncedEvent($channel);

        self::assertSame($channel, $event->channel);
        self::assertTrue($event->channelSetupApplicable);
    }

    #[Test]
    public function channelSetupApplicableCanBeFalse(): void
    {
        $channel = new Channel(new ChannelName('#test'), '', new DateTimeImmutable('@0'));
        $event = new ChannelSyncedEvent($channel, false);

        self::assertFalse($event->channelSetupApplicable);
    }
}
