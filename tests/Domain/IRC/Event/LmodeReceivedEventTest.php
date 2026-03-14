<?php

declare(strict_types=1);

namespace App\Tests\Domain\IRC\Event;

use App\Domain\IRC\Event\LmodeReceivedEvent;
use App\Domain\IRC\ValueObject\ChannelName;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(LmodeReceivedEvent::class)]
final class LmodeReceivedEventTest extends TestCase
{
    #[Test]
    public function constructionAndProperties(): void
    {
        $channelName = new ChannelName('#test');
        $event = new LmodeReceivedEvent($channelName, 'b', ['*!*@bad.host']);

        self::assertSame($channelName, $event->channelName);
        self::assertSame('b', $event->modeChar);
        self::assertSame(['*!*@bad.host'], $event->params);
    }
}
