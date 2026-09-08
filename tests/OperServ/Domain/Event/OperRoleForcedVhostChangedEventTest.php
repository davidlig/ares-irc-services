<?php

declare(strict_types=1);

namespace App\Tests\OperServ\Domain\Event;

use App\OperServ\Domain\Event\OperRoleForcedVhostChangedEvent;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(OperRoleForcedVhostChangedEvent::class)]
final class OperRoleForcedVhostChangedEventTest extends TestCase
{
    #[Test]
    public function properties(): void
    {
        $event = new OperRoleForcedVhostChangedEvent(1, 'staff.example.net');
        $this->assertSame(1, $event->roleId);
        $this->assertSame('staff.example.net', $event->pattern);

        $clearedEvent = new OperRoleForcedVhostChangedEvent(2, null);
        $this->assertSame(2, $clearedEvent->roleId);
        $this->assertNull($clearedEvent->pattern);
    }
}
