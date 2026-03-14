<?php

declare(strict_types=1);

namespace App\Tests\Domain\IRC\Event;

use App\Domain\IRC\Event\Umode2ReceivedEvent;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(Umode2ReceivedEvent::class)]
final class Umode2ReceivedEventTest extends TestCase
{
    #[Test]
    public function constructionAndProperties(): void
    {
        $event = new Umode2ReceivedEvent('UID123', '+r');

        self::assertSame('UID123', $event->sourceId);
        self::assertSame('+r', $event->modeStr);
    }
}
