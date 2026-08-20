<?php

declare(strict_types=1);

namespace App\Tests\Domain\NickServ\Event;

use App\Domain\NickServ\Event\NickVhostChangedEvent;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(NickVhostChangedEvent::class)]
final class NickVhostChangedEventTest extends TestCase
{
    public function testProperties(): void
    {
        $event = new NickVhostChangedEvent(1, 'nick', 'user.vhost.net');
        $this->assertSame(1, $event->nickId);
        $this->assertSame('nick', $event->nickname);
        $this->assertSame('user.vhost.net', $event->vhost);

        $clearedEvent = new NickVhostChangedEvent(2, 'other', null);
        $this->assertSame(2, $clearedEvent->nickId);
        $this->assertSame('other', $clearedEvent->nickname);
        $this->assertNull($clearedEvent->vhost);
    }
}
