<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\IRC\Protocol\UnrealUdb\Event;

use App\Infrastructure\IRC\Protocol\UnrealUdb\Event\UdbSyncRequestedEvent;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(UdbSyncRequestedEvent::class)]
final class UdbSyncRequestedEventTest extends TestCase
{
    public function testProperties(): void
    {
        $event = new UdbSyncRequestedEvent('N', '001');
        $this->assertSame('N', $event->block);
        $this->assertSame('001', $event->sourceSid);
    }
}
