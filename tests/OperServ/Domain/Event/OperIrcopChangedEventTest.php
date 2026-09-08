<?php

declare(strict_types=1);

namespace App\Tests\OperServ\Domain\Event;

use App\OperServ\Domain\Event\OperIrcopChangedEvent;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(OperIrcopChangedEvent::class)]
final class OperIrcopChangedEventTest extends TestCase
{
    #[Test]
    public function properties(): void
    {
        $event = new OperIrcopChangedEvent(42, 'testnick');
        $this->assertSame(42, $event->nickId);
        $this->assertSame('testnick', $event->nickname);
    }
}
