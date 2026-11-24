<?php

declare(strict_types=1);

namespace App\Tests\ChanServ\Adapter\In\Maintenance;

use App\ChanServ\Adapter\In\Maintenance\FlushChannelActivityTask;
use App\ChanServ\Adapter\In\Maintenance\PurgeInactiveChannelsTask;
use App\ChanServ\Adapter\Out\Persistence\ChannelActivityBuffer;
use App\ChanServ\Application\Port\Out\RegisteredChannelRepositoryInterface;
use App\ChanServ\Application\Service\ChanDropService;
use App\ChanServ\Domain\Entity\RegisteredChannel;
use App\Irc\Adapter\In\Maintenance\MaintenanceScheduler;
use ArrayObject;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

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
        $buffer->record(7, new \DateTimeImmutable('2026-01-01 00:00:00 UTC'));
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
                    throw new \RuntimeException('database unavailable');
                }

                return 1;
            },
        );
        $connection->method('fetchOne')->willThrowException(new \RuntimeException('database unavailable'));
        $buffer = new ChannelActivityBuffer($connection);
        $buffer->record(7, new \DateTimeImmutable('2026-01-01 00:00:00 UTC'));
        $task = new FlushChannelActivityTask($buffer);

        $failure = null;
        try {
            $task->run();
        } catch (\RuntimeException $e) {
            $failure = $e;
        }
        self::assertInstanceOf(\RuntimeException::class, $failure);
        self::assertStringStartsWith('Could not flush 1 of 1 chunks', $failure->getMessage());

        self::assertSame(0, $task->getIntervalSeconds());
        $task->run();
        self::assertFalse($buffer->hasPendingActivity(7));
    }

    #[Test]
    public function aFailedFlushSkipsTheInactiveChannelPurgeInTheSameSchedulerCycle(): void
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('executeStatement')->willThrowException(new \RuntimeException('database unavailable'));
        $connection->method('fetchOne')->willThrowException(new \RuntimeException('database unavailable'));
        $buffer = new ChannelActivityBuffer($connection);
        $buffer->record(7, new \DateTimeImmutable('2026-01-01 00:00:00 UTC'));
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

    #[Test]
    public function aChannelRowThatAlwaysFailsDoesNotSkipTheInactiveChannelPurgeForOtherChannels(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $connection->executeStatement('CREATE TABLE registered_channels (id INTEGER PRIMARY KEY, last_used_at DATETIME NULL)');
        $connection->executeStatement('INSERT INTO registered_channels (id, last_used_at) VALUES (7, NULL), (8, NULL)');
        $connection->executeStatement("CREATE TRIGGER poison_row BEFORE UPDATE ON registered_channels WHEN NEW.id = 7 BEGIN SELECT RAISE(ABORT, 'poison row'); END");
        $buffer = new ChannelActivityBuffer($connection);
        $buffer->record(7, new \DateTimeImmutable('-1 minute'));
        $buffer->record(8, new \DateTimeImmutable('-1 minute'));
        $channels = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channels->method('iterateRegisteredInactiveSince')->willReturn([
            $this->inactiveChannel(7, '#poisoned'),
            $this->inactiveChannel(9, '#idle'),
        ]);
        /** @var ArrayObject<int, string> $dropped */
        $dropped = new \ArrayObject();
        $drops = $this->createStub(ChanDropService::class);
        $drops->method('hardDropChannel')->willReturnCallback(static function (RegisteredChannel $channel) use ($dropped): void {
            $dropped->append($channel->getName());
        });
        $purge = new PurgeInactiveChannelsTask($channels, $drops, $buffer, new NullLogger(), 0, 90);
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::never())->method('error');
        $logger->expects(self::never())->method('warning');

        $scheduler = new MaintenanceScheduler([$purge, new FlushChannelActivityTask($buffer)], $logger);
        $scheduler->tick();
        $scheduler->tick();

        self::assertSame(['#idle', '#idle'], $dropped->getArrayCopy());
        self::assertTrue($buffer->hasPendingActivity(7));
        self::assertFalse($buffer->hasPendingActivity(8));
        self::assertNotNull($connection->fetchOne('SELECT last_used_at FROM registered_channels WHERE id = 8'));
    }

    private function inactiveChannel(int $id, string $name): RegisteredChannel
    {
        $channel = $this->createStub(RegisteredChannel::class);
        $channel->method('getId')->willReturn($id);
        $channel->method('getName')->willReturn($name);
        $channel->method('getLastUsedAt')->willReturn(new \DateTimeImmutable('-200 days'));
        $channel->method('getCreatedAt')->willReturn(new \DateTimeImmutable('-300 days'));

        return $channel;
    }
}
