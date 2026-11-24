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

use function array_chunk;
use function array_diff_key;
use function array_intersect_key;
use function array_key_first;
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

    /** On this many consecutive isolated failures, a row is written once more with the current time. */
    private const int CURRENT_TIME_RETRY_AFTER_FAILURES = 3;

    /** A row still failing after the current-time write is warned again every this many consecutive failures. */
    private const int STUCK_ROW_WARNING_INTERVAL = 100;

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

    public function record(int $channelId, \DateTimeImmutable $usedAt): void
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
     * Pending activity is never dropped. On the third consecutive isolated failure the row is
     * written once more, alone, with the current time; when that succeeds the channel leaves
     * pending, otherwise a warning is logged and the row stays pending (still kept from expiry) and
     * keeps being written alone on later flushes; while it keeps failing, the warning is repeated every
     * {@see self::STUCK_ROW_WARNING_INTERVAL} consecutive failures with the attempt count. A successful write resets
     * the count; newer activity only replaces the pending timestamp and keeps counting.
     *
     * A failure that is not row-specific (the database does not answer) is never counted against
     * a row: it keeps the rows pending and is reported with one exception once all chunks were
     * tried, so the maintenance task running this flush fails and the tasks that require it are
     * skipped for the cycle.
     *
     * @throws RuntimeException when at least one chunk could not be written for a reason that is not row-specific
     */
    public function flush(?\DateTimeImmutable $now = null): void
    {
        $now ??= new \DateTimeImmutable();
        $this->pruneThrottleHistory($now);

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
            } catch (\Throwable $exception) {
                $databaseAnswers = $this->databaseAnswers();
                if (!$databaseAnswers || 1 < count($batch)) {
                    $this->logger->error('Could not flush a chunk of deferred ChanServ channel activity; it will be retried.', [
                        'channels' => count($batch),
                        'exception' => $exception,
                    ]);
                }
                if (!$databaseAnswers) {
                    ++$failedChunks;
                    $failedChannels += count($batch);
                    $firstFailure ??= $exception;
                } elseif (1 === count($batch)) {
                    $this->recordRowFailure((int) array_key_first($batch), $exception, $now);
                } else {
                    $this->writeRowsOneByOne($batch, $now);
                }

                continue;
            }

            $this->clearWritten($batch);
        }

        if (null !== $firstFailure) {
            throw new \RuntimeException(sprintf('Could not flush %d of %d chunks of deferred ChanServ channel activity (%d channels kept pending).', $failedChunks, count($chunks), $failedChannels), 0, $firstFailure);
        }
    }

    /**
     * Isolates the rows of a chunk that failed while the database still answers.
     *
     * @param array<int, DateTimeImmutable> $batch
     */
    private function writeRowsOneByOne(array $batch, \DateTimeImmutable $now): void
    {
        foreach ($batch as $channelId => $usedAt) {
            $row = [$channelId => $usedAt];
            try {
                $this->writeBatch($row);
            } catch (\Throwable $exception) {
                $this->recordRowFailure($channelId, $exception, $now);

                continue;
            }
            $this->clearWritten($row);
        }
    }

    /**
     * Counts an isolated failure; the row always stays pending unless the current-time write succeeds.
     */
    private function recordRowFailure(int $channelId, \Throwable $exception, \DateTimeImmutable $now): void
    {
        $attempts = ($this->rowFailures[$channelId] ?? 0) + 1;
        $this->rowFailures[$channelId] = $attempts;
        if (self::CURRENT_TIME_RETRY_AFTER_FAILURES > $attempts) {
            $this->logger->error('Could not flush deferred ChanServ channel activity for one channel; it stays pending and the channel is kept from inactivity expiry.', [
                'channel_id' => $channelId,
                'attempts' => $attempts,
                'exception' => $exception,
            ]);

            return;
        }

        if (self::CURRENT_TIME_RETRY_AFTER_FAILURES < $attempts) {
            if (0 === ($attempts - self::CURRENT_TIME_RETRY_AFTER_FAILURES) % self::STUCK_ROW_WARNING_INTERVAL) {
                $this->logger->warning('Deferred ChanServ channel activity for one channel still cannot be flushed; it stays pending, the channel is kept from inactivity expiry and the row is retried alone on later flushes.', [
                    'channel_id' => $channelId,
                    'attempts' => $attempts,
                    'exception' => $exception,
                ]);
            }

            return;
        }

        try {
            $this->writeBatch([$channelId => $now]);
        } catch (\Throwable $currentTimeFailure) {
            $this->logger->warning('Could not flush deferred ChanServ channel activity for one channel after repeated failures, not even with the current time; it stays pending, the channel is kept from inactivity expiry and the row is retried alone on later flushes.', [
                'channel_id' => $channelId,
                'attempts' => $attempts,
                'exception' => $currentTimeFailure,
            ]);

            return;
        }

        unset($this->pending[$channelId], $this->rowFailures[$channelId]);
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
        } catch (\Throwable) {
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

    private function pruneThrottleHistory(\DateTimeImmutable $now): void
    {
        foreach ($this->lastRecordedAt as $channelId => $recordedAt) {
            if (($now->getTimestamp() - $recordedAt->getTimestamp()) >= self::THROTTLE_WINDOW_SECONDS) {
                unset($this->lastRecordedAt[$channelId]);
            }
        }
    }
}
