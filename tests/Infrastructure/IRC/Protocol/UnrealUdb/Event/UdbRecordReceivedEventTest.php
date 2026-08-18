<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\IRC\Protocol\UnrealUdb\Event;

use App\Infrastructure\IRC\Protocol\UnrealUdb\Event\UdbRecordReceivedEvent;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(UdbRecordReceivedEvent::class)]
final class UdbRecordReceivedEventTest extends TestCase
{
    #[Test]
    public function itExposesProperties(): void
    {
        $event = new UdbRecordReceivedEvent('N::nick', 'value');
        $this->assertSame('N::nick', $event->key);
        $this->assertSame('value', $event->value);
    }
}
