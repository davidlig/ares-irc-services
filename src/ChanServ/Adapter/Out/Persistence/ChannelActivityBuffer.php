<?php

declare(strict_types=1);

namespace App\ChanServ\Adapter\Out\Persistence;

use App\ChanServ\Application\Port\Out\ChannelActivityPendingQuery;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use RuntimeException;
use Throwable;

use function array_chunk;
use function array_diff_key;
use function array_intersect_key;
use function count;
use function implode;
use function sprintf;

/**
 * Buffers channel activity independently of Doctrine's identity map.
 *
 * @internal
 */
final class ChannelActivityBuffer implements ChannelActivityPendingQuery
{
    private const int THROTTLE_WINDOW_SECONDS = 60;

    /** Three bound parameters per channel keeps each statement at 600 parameters. */
    private const int CHANNELS_PER_STATEMENT = 200;

    /** A row that fails on its own this many flushes in a row is dropped. */
    private const int MAX_CONSECUTIVE_ROW_FAILURES = 3;

    /** @var array<int, DateTimeImmutable> */
    private array $pending = [];

    /** @var array<int, DateTimeImmutable> */
    private array $lastRecordedAt = [];

    /**
     * Consecutive isolated (row-specific) write failures per pending channel.
     *
     * @var array<int, int>
     */
    private array $rowFailures = [];

    public function __construct(
        private readonly Connection $connection,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {}

    public function record(int $channelId, DateTimeImmutable $usedAt): void
    {
        $this->pruneThrottleHistory($usedAt);

        $lastRecordedAt = $this->lastRecordedAt[$channelId] ?? null;
        if (null !== $lastRecordedAt && ($usedAt->getTimestamp() - $lastRecordedAt->getTimestamp()) < self::THROTTLE_WINDOW_SECONDS) {
            return;
        }

        $this->lastRecordedAt[$channelId] = $usedAt;
        $this->pending[$channelId] = $usedAt;
    }

    public function hasPendingActivity(int $channelId): bool
    {
        return isset($this->pending[$channelId]);
    }

    /**
     * Writes pending activity in chunks so no statement binds an unbounded number of parameters.
     *
     * Every chunk is attempted and written chunks are cleared. When a chunk fails while the
     * database still answers, its rows are retried one by one. A row that also fails on its own
     * is logged and stays pending (so the inactive-channel purge keeps that channel), without
     * failing the flush, so expiry still runs for every other channel. Such a known-bad row is
     * written on its own on later flushes, so it no longer splits the chunk of the other rows.
     * After three consecutive isolated failures it is dropped with a warning: the channel loses
     * that activity timestamp but can expire for inactivity again. A successful write resets the
     * count; newer activity only replaces the pending timestamp and keeps counting.
     *
     * A failure that is not row-specific (the database does not answer) is never counted against
     * a row: it keeps the rows pending and is reported with one exception once all chunks were
     * tried, so the maintenance task running this flush fails and the tasks that require it are
     * skipped for the cycle.
     *
     * @throws RuntimeException when at least one chunk could not be written for a reason that is not row-specific
     */
    public function flush(?DateTimeImmutable $now = null): void
    {
        $this->pruneThrottleHistory($now ?? new DateTimeImmutable());

        $knownBad = array_intersect_key($this->pending, $this->rowFailures);
        $chunks = [
            ...array_chunk(array_diff_key($this->pending, $this->rowFailures), self::CHANNELS_PER_STATEMENT, true),
            ...array_chunk($knownBad, 1, true),
        ];
        $failedChunks = 0;
        $failedChannels = 0;
        $firstFailure = null;

        foreach ($chunks as $batch) {
            try {
                $this->writeBatch($batch);
            } catch (Throwable $exception) {
                if (!$this->databaseAnswers()) {
                    $this->logger->error('Could not flush a chunk of deferred ChanServ channel activity; it will be retried.', [
                        'channels' => count($batch),
                        'exception' => $exception,
                    ]);
                    ++$failedChunks;
                    $failedChannels += count($batch);
                    $firstFailure ??= $exception;

                    continue;
                }
                if (1 < count($batch)) {
                    $this->logger->error('Could not flush a chunk of deferred ChanServ channel activity; it will be retried.', [
                        'channels' => count($batch),
                        'exception' => $exception,
                    ]);
                }
                $this->writeRowsOneByOne($batch, $exception);

                continue;
            }

            $this->clearWritten($batch);
        }

        if (null !== $firstFailure) {
            throw new RuntimeException(sprintf('Could not flush %d of %d chunks of deferred ChanServ channel activity (%d channels kept pending).', $failedChunks, count($chunks), $failedChannels), 0, $firstFailure);
        }
    }

    /**
     * Isolates the rows of a chunk that failed while the database still answers.
     *
     * A one-row chunk already failed on its own, so it is not written again.
     *
     * @param array<int, DateTimeImmutable> $batch
     */
    private function writeRowsOneByOne(array $batch, Throwable $chunkFailure): void
    {
        foreach ($batch as $channelId => $usedAt) {
            $row = [$channelId => $usedAt];
            try {
                if (1 === count($batch)) {
                    throw $chunkFailure;
                }
                $this->writeBatch($row);
            } catch (Throwable $exception) {
                $this->recordRowFailure($channelId, $exception);

                continue;
            }
            $this->clearWritten($row);
        }
    }

    private function recordRowFailure(int $channelId, Throwable $exception): void
    {
        $attempts = ($this->rowFailures[$channelId] ?? 0) + 1;
        if (self::MAX_CONSECUTIVE_ROW_FAILURES > $attempts) {
            $this->rowFailures[$channelId] = $attempts;
            $this->logger->error('Could not flush deferred ChanServ channel activity for one channel; it stays pending and the channel is kept from inactivity expiry.', [
                'channel_id' => $channelId,
                'attempts' => $attempts,
                'exception' => $exception,
            ]);

            return;
        }

        unset($this->pending[$channelId], $this->rowFailures[$channelId]);
        $this->logger->warning('Dropped deferred ChanServ channel activity for one channel after repeated failures; the channel can expire for inactivity again.', [
            'channel_id' => $channelId,
            'attempts' => $attempts,
            'exception' => $exception,
        ]);
    }

    /**
     * @param array<int, DateTimeImmutable> $batch
     */
    private function clearWritten(array $batch): void
    {
        foreach ($batch as $channelId => $usedAt) {
            unset($this->rowFailures[$channelId]);
            if (($this->pending[$channelId] ?? null) === $usedAt) {
                unset($this->pending[$channelId]);
            }
        }
    }

    private function databaseAnswers(): bool
    {
        try {
            $this->connection->fetchOne($this->connection->getDatabasePlatform()->getDummySelectSQL());
        } catch (Throwable) {
            return false;
        }

        return true;
    }

    /**
     * @param array<int, DateTimeImmutable> $batch
     */
    private function writeBatch(array $batch): void
    {
        $cases = [];
        $ids = [];
        $params = [];
        $types = [];

        foreach ($batch as $channelId => $usedAt) {
            $index = count($ids);
            $caseId = 'case_id_' . $index;
            $id = 'id_' . $index;
            $date = 'last_used_at_' . $index;

            $cases[] = 'WHEN :' . $caseId . ' THEN :' . $date;
            $ids[] = ':' . $id;
            $params[$caseId] = $channelId;
            $params[$id] = $channelId;
            $params[$date] = $usedAt->format('Y-m-d H:i:s');
            $types[$caseId] = ParameterType::INTEGER;
            $types[$id] = ParameterType::INTEGER;
            $types[$date] = ParameterType::STRING;
        }

        $sql = sprintf(
            'UPDATE registered_channels SET last_used_at = CASE id %s ELSE last_used_at END WHERE id IN (%s)',
            implode(' ', $cases),
            implode(', ', $ids),
        );

        $this->connection->executeStatement($sql, $params, $types);
    }

    private function pruneThrottleHistory(DateTimeImmutable $now): void
    {
        foreach ($this->lastRecordedAt as $channelId => $recordedAt) {
            if (($now->getTimestamp() - $recordedAt->getTimestamp()) >= self::THROTTLE_WINDOW_SECONDS) {
                unset($this->lastRecordedAt[$channelId]);
            }
        }
    }
}
