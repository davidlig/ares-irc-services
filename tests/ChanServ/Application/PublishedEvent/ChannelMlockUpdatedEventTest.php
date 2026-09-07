<?php

declare(strict_types=1);

namespace App\Tests\ChanServ\Application\PublishedEvent;

use App\ChanServ\Application\PublishedEvent\ChannelMlockUpdatedEvent;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ChannelMlockUpdatedEvent::class)]
final class ChannelMlockUpdatedEventTest extends TestCase
{
    #[Test]
    public function holdsChannelName(): void
    {
        $event = new ChannelMlockUpdatedEvent('#test');

        self::assertSame('#test', $event->channelName);
    }
}
