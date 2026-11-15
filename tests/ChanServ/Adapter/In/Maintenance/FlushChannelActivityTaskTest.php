<?php

declare(strict_types=1);

namespace App\Tests\ChanServ\Adapter\In\Maintenance;

use App\ChanServ\Adapter\In\Maintenance\FlushChannelActivityTask;
use App\ChanServ\Adapter\In\Maintenance\PurgeInactiveChannelsTask;
use App\ChanServ\Adapter\Out\Persistence\ChannelActivityBuffer;
use App\ChanServ\Application\Port\Out\RegisteredChannelRepositoryInterface;
use App\ChanServ\Application\Service\ChanDropService;
use App\Irc\Adapter\In\Maintenance\MaintenanceScheduler;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
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

        $failure = null;
        try {
            $task->run();
        } catch (RuntimeException $e) {
            $failure = $e;
        }
        self::assertInstanceOf(RuntimeException::class, $failure);
        self::assertStringStartsWith('Could not flush 1 of 1 chunks', $failure->getMessage());

        self::assertSame(0, $task->getIntervalSeconds());
        $task->run();
        self::assertFalse($buffer->hasPendingActivity(7));
    }

    #[Test]
    public function aFailedFlushSkipsTheInactiveChannelPurgeInTheSameSchedulerCycle(): void
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('executeStatement')->willThrowException(new RuntimeException('database unavailable'));
        $buffer = new ChannelActivityBuffer($connection);
        $buffer->record(7, new DateTimeImmutable('2026-01-01 00:00:00 UTC'));
        $channels = $this->createMock(RegisteredChannelRepositoryInterface::class);
        $channels->expects(self::never())->method('iterateRegisteredInactiveSince');
        $purge = new PurgeInactiveChannelsTask($channels, $this->createStub(ChanDropService::class), $buffer, new NullLogger(), 0, 90);
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('error')->with('Maintenance task failed', self::callback(
            static fn (array $context): bool => FlushChannelActivityTask::NAME === $context['task'],
        ));
        $logger->expects(self::once())->method('warning')->with('Maintenance task skipped: a prerequisite failed in this cycle', [
            'task' => 'chanserv.purge_inactive_channels',
            'prerequisite' => FlushChannelActivityTask::NAME,
        ]);

        new MaintenanceScheduler([$purge, new FlushChannelActivityTask($buffer)], $logger)->tick();
    }
}
