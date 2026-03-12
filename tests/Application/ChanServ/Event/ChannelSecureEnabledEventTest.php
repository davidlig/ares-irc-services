<?php

declare(strict_types=1);

namespace App\Tests\Application\ChanServ\Event;

use App\Application\ChanServ\Event\ChannelSecureEnabledEvent;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ChannelSecureEnabledEvent::class)]
final class ChannelSecureEnabledEventTest extends TestCase
{
    #[Test]
    public function holdsChannelName(): void
    {
        $event = new ChannelSecureEnabledEvent('#test');

        self::assertSame('#test', $event->channelName);
    }
}
