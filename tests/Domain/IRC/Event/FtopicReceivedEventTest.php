<?php

declare(strict_types=1);

namespace App\Tests\Domain\IRC\Event;

use App\Domain\IRC\Event\FtopicReceivedEvent;
use App\Domain\IRC\ValueObject\ChannelName;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(FtopicReceivedEvent::class)]
final class FtopicReceivedEventTest extends TestCase
{
    #[Test]
    public function constructionAndProperties(): void
    {
        $channelName = new ChannelName('#test');
        $event = new FtopicReceivedEvent($channelName, 'Welcome to the channel');

        self::assertSame($channelName, $event->channelName);
        self::assertSame('Welcome to the channel', $event->topic);
        self::assertNull($event->setterNick);
    }

    #[Test]
    public function setterNickCanBeProvided(): void
    {
        $channelName = new ChannelName('#test');
        $event = new FtopicReceivedEvent($channelName, null, 'OpNick');

        self::assertSame('OpNick', $event->setterNick);
    }
}
