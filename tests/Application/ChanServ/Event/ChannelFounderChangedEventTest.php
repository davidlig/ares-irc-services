<?php

declare(strict_types=1);

namespace App\Tests\Application\ChanServ\Event;

use App\Application\ChanServ\Event\ChannelFounderChangedEvent;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ChannelFounderChangedEvent::class)]
final class ChannelFounderChangedEventTest extends TestCase
{
    #[Test]
    public function holdsChannelName(): void
    {
        $event = new ChannelFounderChangedEvent('#test');

        self::assertSame('#test', $event->channelName);
    }
}
