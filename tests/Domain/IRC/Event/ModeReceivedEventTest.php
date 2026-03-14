<?php

declare(strict_types=1);

namespace App\Tests\Domain\IRC\Event;

use App\Domain\IRC\Event\ModeReceivedEvent;
use App\Domain\IRC\ValueObject\ChannelName;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ModeReceivedEvent::class)]
final class ModeReceivedEventTest extends TestCase
{
    #[Test]
    public function constructionAndProperties(): void
    {
        $channelName = new ChannelName('#test');
        $event = new ModeReceivedEvent($channelName, '+o', ['nick']);

        self::assertSame($channelName, $event->channelName);
        self::assertSame('+o', $event->modeStr);
        self::assertSame(['nick'], $event->modeParams);
    }
}
