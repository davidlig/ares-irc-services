<?php

declare(strict_types=1);

namespace App\Tests\Domain\IRC\Event;

use App\Domain\IRC\Event\FmodeReceivedEvent;
use App\Domain\IRC\ValueObject\ChannelName;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(FmodeReceivedEvent::class)]
final class FmodeReceivedEventTest extends TestCase
{
    #[Test]
    public function constructionAndProperties(): void
    {
        $channelName = new ChannelName('#test');
        $event = new FmodeReceivedEvent($channelName, '+nt');

        self::assertSame($channelName, $event->channelName);
        self::assertSame('+nt', $event->modeStr);
    }
}
