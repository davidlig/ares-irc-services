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

    /** @var array<int, DateTimeImmutable> */
    private array $pending = [];

    /** @var array<int, DateTimeImmutable> */
    private array $lastRecordedAt = [];

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
     * Every chunk is attempted: a failing chunk is logged and kept pending for the next flush,
     * already-written chunks are cleared. Once all chunks were tried, any failure is reported
     * with one exception, so the maintenance task running this flush fails and the tasks that
     * require it are skipped for the cycle.
     *
     * @throws RuntimeException when at least one chunk could not be written
     */
    public function flush(?DateTimeImmutable $now = null): void
    {
        $this->pruneThrottleHistory($now ?? new DateTimeImmutable());

        $chunks = array_chunk($this->pending, self::CHANNELS_PER_STATEMENT, true);
        $failedChunks = 0;
        $failedChannels = 0;
        $firstFailure = null;

        foreach ($chunks as $batch) {
            try {
                $this->writeBatch($batch);
            } catch (Throwable $exception) {
                ++$failedChunks;
                $failedChannels += count($batch);
                $firstFailure ??= $exception;
                $this->logger->error('Could not flush a chunk of deferred ChanServ channel activity; it will be retried.', [
                    'channels' => count($batch),
                    'exception' => $exception,
                ]);

                continue;
            }

            foreach ($batch as $channelId => $usedAt) {
                if (($this->pending[$channelId] ?? null) === $usedAt) {
                    unset($this->pending[$channelId]);
                }
            }
        }

        if (null !== $firstFailure) {
            throw new RuntimeException(sprintf('Could not flush %d of %d chunks of deferred ChanServ channel activity (%d channels kept pending).', $failedChunks, count($chunks), $failedChannels), 0, $firstFailure);
        }
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
