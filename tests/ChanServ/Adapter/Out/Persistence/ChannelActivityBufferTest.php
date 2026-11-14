<?php

declare(strict_types=1);

namespace App\Tests\ChanServ\Adapter\Out\Persistence;

use App\ChanServ\Adapter\Out\Persistence\ChannelActivityBuffer;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionProperty;
use RuntimeException;

use function array_filter;
use function array_map;
use function array_unique;
use function array_values;
use function count;
use function str_contains;
use function str_starts_with;

#[CoversClass(ChannelActivityBuffer::class)]
final class ChannelActivityBufferTest extends TestCase
{
    #[Test]
    public function itThrottlesPerChannelAndFlushesDistinctTimestampsInOneUpdate(): void
    {
        $first = new DateTimeImmutable('2026-01-01 00:00:00 UTC');
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())->method('executeStatement')
            ->with(
                self::callback(static fn (string $sql): bool => str_starts_with($sql, 'UPDATE registered_channels')
                    && str_contains($sql, 'CASE id')
                    && str_contains($sql, 'WHERE id IN (')),
                self::callback(static function (array $params): bool {
                    self::assertSame([1, 2], array_values(array_unique(array_filter($params, 'is_int'))));
                    self::assertContains('2026-01-01 00:00:30', $params);
                    self::assertContains('2026-01-01 00:01:01', $params);

                    return true;
                }),
                self::anything(),
            )
            ->willReturn(2);

        $buffer = new ChannelActivityBuffer($connection);
        $buffer->record(1, $first);
        $buffer->record(2, $first->modify('+30 seconds'));
        $buffer->record(1, $first->modify('+30 seconds'));
        $buffer->record(1, $first->modify('+61 seconds'));

        $buffer->flush();
    }

    #[Test]
    public function itRetainsPendingUpdatesWhenTheDatabaseWriteFailsAndLogsInsteadOfThrowing(): void
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
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('error')->with(
            'Could not flush a chunk of deferred ChanServ channel activity; it will be retried.',
            self::callback(static function (array $context): bool {
                self::assertSame(1, $context['channels']);
                self::assertInstanceOf(RuntimeException::class, $context['exception']);

                return true;
            }),
        );
        $buffer = new ChannelActivityBuffer($connection, $logger);
        $buffer->record(7, new DateTimeImmutable('2026-01-01 00:00:00 UTC'));

        $buffer->flush();
        $buffer->flush();
        $buffer->flush();
    }

    #[Test]
    public function itWritesEveryPendingChannelAcrossSeveralChunksOnARealConnection(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $connection->executeStatement('CREATE TABLE registered_channels (id INTEGER PRIMARY KEY, last_used_at DATETIME NULL)');
        $channelCount = 450;
        $connection->executeStatement('WITH RECURSIVE seq(n) AS (SELECT 1 UNION ALL SELECT n + 1 FROM seq WHERE n < ' . $channelCount . ') INSERT INTO registered_channels (id, last_used_at) SELECT n, NULL FROM seq');
        $buffer = new ChannelActivityBuffer($connection);
        $usedAt = new DateTimeImmutable('2026-01-01 00:00:00 UTC');
        for ($id = 1; $id <= $channelCount; ++$id) {
            $buffer->record($id, $usedAt);
        }

        $buffer->flush($usedAt);

        self::assertSame($channelCount, $connection->fetchOne("SELECT COUNT(*) FROM registered_channels WHERE last_used_at = '2026-01-01 00:00:00'"));
        $pending = new ReflectionProperty($buffer, 'pending');
        self::assertSame([], $pending->getValue($buffer));
    }

    #[Test]
    public function itBindsABoundedNumberOfParametersPerStatementAndContinuesAfterAFailedChunk(): void
    {
        $connection = $this->createMock(Connection::class);
        $statements = [];
        $connection->expects(self::exactly(4))->method('executeStatement')->willReturnCallback(
            static function (string $sql, array $params) use (&$statements): int {
                $statements[] = $params;
                if (1 === count($statements)) {
                    throw new RuntimeException('lock timeout');
                }

                return 1;
            },
        );
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('error');
        $buffer = new ChannelActivityBuffer($connection, $logger);
        $usedAt = new DateTimeImmutable('2026-01-01 00:00:00 UTC');
        for ($id = 1; $id <= 450; ++$id) {
            $buffer->record($id, $usedAt);
        }

        $buffer->flush($usedAt);
        $buffer->flush($usedAt);

        self::assertCount(4, $statements);
        foreach ($statements as $params) {
            self::assertLessThanOrEqual(600, count($params));
        }
        self::assertSame([600, 600, 150, 600], array_map('count', $statements));
        self::assertSame($statements[0], $statements[3], 'Only the failed chunk is retried on the next flush.');
    }

    #[Test]
    public function itPrunesExpiredThrottleHistoryAndUpdatesDistinctChannelRows(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $connection->executeStatement('CREATE TABLE registered_channels (id INTEGER PRIMARY KEY, last_used_at DATETIME NULL)');
        $connection->executeStatement('INSERT INTO registered_channels (id, last_used_at) VALUES (1, NULL), (2, NULL)');
        $buffer = new ChannelActivityBuffer($connection);
        $old = new DateTimeImmutable('2026-01-01 00:00:00 UTC');

        $buffer->record(1, $old);
        $buffer->record(2, $old->modify('+30 seconds'));
        $buffer->flush();

        $buffer->record(1, $old->modify('+10 seconds'));
        $buffer->flush();

        self::assertSame('2026-01-01 00:00:10', $connection->fetchOne('SELECT last_used_at FROM registered_channels WHERE id = 1'));
        self::assertSame('2026-01-01 00:00:30', $connection->fetchOne('SELECT last_used_at FROM registered_channels WHERE id = 2'));
    }

    #[Test]
    public function itPrunesThrottleHistoryOnAnEmptyFlush(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())->method('executeStatement')->willReturn(1);
        $buffer = new ChannelActivityBuffer($connection);
        $usedAt = new DateTimeImmutable('2026-01-01 00:00:00 UTC');
        $buffer->record(7, $usedAt);
        $buffer->flush($usedAt->modify('+30 seconds'));
        $buffer->flush($usedAt->modify('+61 seconds'));

        $history = new ReflectionProperty($buffer, 'lastRecordedAt');
        self::assertSame([], $history->getValue($buffer));
    }
}
