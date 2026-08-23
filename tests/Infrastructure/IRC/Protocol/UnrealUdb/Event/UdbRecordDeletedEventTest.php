<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\IRC\Protocol\UnrealUdb\Event;

use App\Infrastructure\IRC\Protocol\UnrealUdb\Event\UdbRecordDeletedEvent;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(UdbRecordDeletedEvent::class)]
final class UdbRecordDeletedEventTest extends TestCase
{
    public function testProperties(): void
    {
        $event = new UdbRecordDeletedEvent('N::ghost::pass');

        self::assertSame('N::ghost::pass', $event->key);
    }
}
