<?php

declare(strict_types=1);

namespace App\Tests\Domain\IRC\Event;

use App\Domain\IRC\Connection\ConnectionInterface;
use App\Domain\IRC\Event\NetworkSyncCompleteEvent;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
#[CoversClass(NetworkSyncCompleteEvent::class)]
final class NetworkSyncCompleteEventTest extends TestCase
{
    #[Test]
    public function constructionAndProperties(): void
    {
        $connection = $this->createMock(ConnectionInterface::class);
        $event = new NetworkSyncCompleteEvent($connection, '001');

        self::assertSame($connection, $event->connection);
        self::assertSame('001', $event->serverSid);
    }
}
