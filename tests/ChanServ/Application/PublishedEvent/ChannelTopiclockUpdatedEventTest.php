<?php

declare(strict_types=1);

namespace App\Tests\ChanServ\Application\PublishedEvent;

use App\ChanServ\Application\PublishedEvent\ChannelTopiclockUpdatedEvent;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ChannelTopiclockUpdatedEvent::class)]
final class ChannelTopiclockUpdatedEventTest extends TestCase
{
    #[Test]
    public function instantiatesWithChannelName(): void
    {
        $event = new ChannelTopiclockUpdatedEvent('#canal');

        self::assertSame('#canal', $event->channelName);
    }
}
