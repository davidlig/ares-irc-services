<?php

declare(strict_types=1);

namespace App\Tests\Irc\Domain\Event;

use App\Irc\Domain\Event\ServerDelinkedEvent;
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
        self::assertSame(['002'], $event->serverSids);
    }

    #[Test]
    public function reasonDefaultsToEmpty(): void
    {
        $event = new ServerDelinkedEvent('001');

        self::assertSame('', $event->reason);
        self::assertSame(['001'], $event->serverSids);
    }

    #[Test]
    public function serverSidsPopulatedExplicitlyAndDeduplicated(): void
    {
        $event = new ServerDelinkedEvent('002', 'split', ['002', '003', '002']);

        self::assertSame('002', $event->serverSid);
        self::assertSame('split', $event->reason);
        self::assertSame(['002', '003'], $event->serverSids);
    }

    #[Test]
    public function serverSidsEmptyWhenSidEmptyAndListEmpty(): void
    {
        $event = new ServerDelinkedEvent('');

        self::assertSame('', $event->serverSid);
        self::assertSame([], $event->serverSids);
    }
}
