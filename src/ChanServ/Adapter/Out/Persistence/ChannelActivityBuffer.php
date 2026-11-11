<?php

declare(strict_types=1);

namespace App\ChanServ\Adapter\Out\Persistence;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;

use function count;
use function implode;
use function sprintf;

/**
 * Buffers channel activity independently of Doctrine's identity map.
 *
 * @internal
 */
final class ChannelActivityBuffer
{
    private const int THROTTLE_WINDOW_SECONDS = 60;

    /** @var array<int, DateTimeImmutable> */
    private array $pending = [];

    /** @var array<int, DateTimeImmutable> */
    private array $lastRecordedAt = [];

    public function __construct(private readonly Connection $connection) {}

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

    public function flush(?DateTimeImmutable $now = null): void
    {
        $this->pruneThrottleHistory($now ?? new DateTimeImmutable());
        if ([] === $this->pending) {
            return;
        }

        $batch = $this->pending;
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

        foreach ($batch as $channelId => $usedAt) {
            if (($this->pending[$channelId] ?? null) === $usedAt) {
                unset($this->pending[$channelId]);
            }
        }
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
