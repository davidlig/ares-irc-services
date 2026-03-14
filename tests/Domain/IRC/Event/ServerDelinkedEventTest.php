<?php

declare(strict_types=1);

namespace App\Tests\Domain\IRC\Event;

use App\Domain\IRC\Event\ServerDelinkedEvent;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ServerDelinkedEvent::class)]
final class ServerDelinkedEventTest extends TestCase
{
    #[Test]
    public function constructionAndProperties(): void
    {
        $event = new ServerDelinkedEvent('002', 'Remote host closed the connection');

        self::assertSame('002', $event->serverSid);
        self::assertSame('Remote host closed the connection', $event->reason);
    }

    #[Test]
    public function reasonDefaultsToEmpty(): void
    {
        $event = new ServerDelinkedEvent('001');

        self::assertSame('', $event->reason);
    }
}
