<?php

declare(strict_types=1);

namespace App\Tests\Irc\Adapter\Event;

use App\Irc\Adapter\Event\NetworkBurstCompleteEvent;
use App\Irc\Adapter\Out\Connection\ConnectionInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(NetworkBurstCompleteEvent::class)]
final class NetworkBurstCompleteEventTest extends TestCase
{
    #[Test]
    public function constructionAndProperties(): void
    {
        $connection = $this->createStub(ConnectionInterface::class);
        $event = new NetworkBurstCompleteEvent($connection, '001');

        self::assertSame($connection, $event->connection);
        self::assertSame('001', $event->serverSid);
    }
}
