<?php

declare(strict_types=1);

namespace App\Tests\Domain\IRC\Event;

use App\Domain\IRC\Event\QuitReceivedEvent;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(QuitReceivedEvent::class)]
final class QuitReceivedEventTest extends TestCase
{
    #[Test]
    public function constructionAndProperties(): void
    {
        $event = new QuitReceivedEvent('UID123', 'Connection closed');

        self::assertSame('UID123', $event->sourceId);
        self::assertSame('Connection closed', $event->reason);
    }
}
