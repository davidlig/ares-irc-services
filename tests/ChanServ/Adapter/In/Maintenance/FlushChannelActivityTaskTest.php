<?php

declare(strict_types=1);

namespace App\Tests\ChanServ\Adapter\In\Maintenance;

use App\ChanServ\Adapter\In\Maintenance\FlushChannelActivityTask;
use App\ChanServ\Adapter\Out\Persistence\ChannelActivityBuffer;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(FlushChannelActivityTask::class)]
final class FlushChannelActivityTaskTest extends TestCase
{
    #[Test]
    public function itFlushesDeferredActivityBeforeInactiveChannelExpiry(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())->method('executeStatement')
            ->with(self::stringContains('UPDATE registered_channels'), self::anything(), self::anything())
            ->willReturn(1);
        $buffer = new ChannelActivityBuffer($connection);
        $buffer->record(7, new DateTimeImmutable('2026-01-01 00:00:00 UTC'));
        $task = new FlushChannelActivityTask($buffer);

        self::assertSame('chanserv.flush_channel_activity', $task->getName());
        self::assertSame(0, $task->getIntervalSeconds());
        self::assertLessThan(300, $task->getOrder());
        $task->run();
    }

    #[Test]
    public function itRetriesOnTheNextMaintenanceDispatchAfterAPreviousWriteFailure(): void
    {
        $connection = $this->createMock(Connection::class);
        $attempts = 0;
        $connection->expects(self::exactly(2))->method('executeStatement')->willReturnCallback(
            static function () use (&$attempts): int {
                if (0 === $attempts++) {
                    throw new RuntimeException('database unavailable');
                }

                return 1;
            },
        );
        $buffer = new ChannelActivityBuffer($connection);
        $buffer->record(7, new DateTimeImmutable('2026-01-01 00:00:00 UTC'));
        $task = new FlushChannelActivityTask($buffer);

        try {
            $task->run();
            self::fail('The first maintenance flush should fail.');
        } catch (RuntimeException $exception) {
            self::assertSame('database unavailable', $exception->getMessage());
        }

        self::assertSame(0, $task->getIntervalSeconds());
        $task->run();
    }
}
