<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\IRC\Protocol\UnrealUdb\Event;

use App\Infrastructure\IRC\Protocol\UnrealUdb\Event\UdbSyncCompleteEvent;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(UdbSyncCompleteEvent::class)]
final class UdbSyncCompleteEventTest extends TestCase
{
    public function testProperties(): void
    {
        $event = new UdbSyncCompleteEvent('N', '001');
        $this->assertSame('N', $event->block);
        $this->assertSame('001', $event->sourceSid);
    }
}
