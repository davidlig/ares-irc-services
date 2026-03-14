<?php

declare(strict_types=1);

namespace App\Tests\Domain\IRC\Event;

use App\Domain\IRC\Event\SethostReceivedEvent;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(SethostReceivedEvent::class)]
final class SethostReceivedEventTest extends TestCase
{
    #[Test]
    public function constructionAndProperties(): void
    {
        $event = new SethostReceivedEvent('UID123', 'vhost.example.com');

        self::assertSame('UID123', $event->sourceId);
        self::assertSame('vhost.example.com', $event->newHost);
    }
}
