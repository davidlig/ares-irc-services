<?php

declare(strict_types=1);

namespace App\Tests\Domain\OperServ\Event;

use App\Domain\OperServ\Event\OperIrcopChangedEvent;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(OperIrcopChangedEvent::class)]
final class OperIrcopChangedEventTest extends TestCase
{
    public function testProperties(): void
    {
        $event = new OperIrcopChangedEvent(42, 'testnick');
        $this->assertSame(42, $event->nickId);
        $this->assertSame('testnick', $event->nickname);
    }
}
