<?php

declare(strict_types=1);

namespace App\Tests\Domain\NickServ\Event;

use App\Domain\NickServ\Event\NickPasswordProvidedEvent;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(NickPasswordProvidedEvent::class)]
final class NickPasswordProvidedEventTest extends TestCase
{
    #[Test]
    public function properties(): void
    {
        $event = new NickPasswordProvidedEvent(1, 'nick', 'pass', '$2y$12$hash');

        $this->assertSame(1, $event->nickId);
        $this->assertSame('nick', $event->nickname);
        $this->assertSame('pass', $event->plaintextPassword);
        $this->assertSame('$2y$12$hash', $event->passwordHash);
    }

    #[Test]
    public function passwordHashIsNullable(): void
    {
        $event = new NickPasswordProvidedEvent(null, 'nick', 'pass', null);

        $this->assertNull($event->nickId);
        $this->assertNull($event->passwordHash);
    }
}
