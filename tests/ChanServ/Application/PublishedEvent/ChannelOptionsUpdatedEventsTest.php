<?php

declare(strict_types=1);

namespace App\Tests\ChanServ\Application\PublishedEvent;

use App\ChanServ\Application\PublishedEvent\ChannelIrcopOnlyUpdatedEvent;
use App\ChanServ\Application\PublishedEvent\ChannelSecureUpdatedEvent;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ChannelIrcopOnlyUpdatedEvent::class)]
#[CoversClass(ChannelSecureUpdatedEvent::class)]
final class ChannelOptionsUpdatedEventsTest extends TestCase
{
    #[Test]
    public function eventsCarryTheChangedChannel(): void
    {
        self::assertSame('#ops', new ChannelIrcopOnlyUpdatedEvent('#ops')->channelName);
        self::assertSame('#secure', new ChannelSecureUpdatedEvent('#secure')->channelName);
    }
}
