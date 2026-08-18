<?php

declare(strict_types=1);

namespace App\Tests\Domain\NickServ\Event;

use App\Domain\NickServ\Event\NickPasswordProvidedEvent;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(NickPasswordProvidedEvent::class)]
final class NickPasswordProvidedEventTest extends TestCase
{
    public function testProperties(): void
    {
        $event = new NickPasswordProvidedEvent(1, 'nick', 'pass');
        $this->assertSame(1, $event->nickId);
        $this->assertSame('nick', $event->nickname);
        $this->assertSame('pass', $event->plaintextPassword);
    }
}
