<?php

declare(strict_types=1);

namespace App\Tests\Domain\IRC\Event;

use App\Domain\IRC\Connection\ConnectionInterface;
use App\Domain\IRC\Event\NetworkBurstCompleteEvent;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
#[CoversClass(NetworkBurstCompleteEvent::class)]
final class NetworkBurstCompleteEventTest extends TestCase
{
    #[Test]
    public function constructionAndProperties(): void
    {
        $connection = $this->createMock(ConnectionInterface::class);
        $event = new NetworkBurstCompleteEvent($connection, '001');

        self::assertSame($connection, $event->connection);
        self::assertSame('001', $event->serverSid);
    }
}
