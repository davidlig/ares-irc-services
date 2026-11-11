<?php

declare(strict_types=1);

namespace App\MemoServ\Application\UseCase\GetPendingNickNotice;

use function array_fill_keys;
use function array_key_exists;
use function array_key_last;
use function array_splice;
use function array_unique;
use function array_values;
use function in_array;

/** Holds bounded unread counts for the currently dispatched nickname burst batch. */
final class BurstMemoNoticeCache
{
    /** @var list<array{nickIds: list<int>, counts: array<int, int>, valid: bool, token: object}> */
    private array $batches = [];

    /** @param list<int> $nickIds
     * @param array<int, int> $counts
     */
    public function beginBatch(array $nickIds, array $counts, object $token): void
    {
        $ids = array_values(array_unique($nickIds));
        $normalized = array_fill_keys($ids, 0);
        foreach ($counts as $nickId => $count) {
            if (array_key_exists($nickId, $normalized)) {
                $normalized[$nickId] = $count;
            }
        }

        $this->batches[] = ['nickIds' => $ids, 'counts' => $normalized, 'valid' => true, 'token' => $token];
    }

    public function hasActiveBatch(): bool
    {
        return [] !== $this->batches;
    }

    /** @return list<int> */
    public function activeNickIds(): array
    {
        $index = array_key_last($this->batches);
        if (null === $index) {
            return [];
        }

        return $this->batches[$index]['nickIds'];
    }

    public function countFor(int $nickId): ?int
    {
        $index = array_key_last($this->batches);
        if (null === $index) {
            return null;
        }

        $batch = $this->batches[$index];
        if (!$batch['valid']) {
            return null;
        }

        return $batch['counts'][$nickId] ?? null;
    }

    public function includesNickId(int $nickId): bool
    {
        $index = array_key_last($this->batches);
        if (null === $index) {
            return false;
        }

        return in_array($nickId, $this->batches[$index]['nickIds'], true);
    }

    /** @param array<int, int> $counts */
    public function replaceActiveCounts(array $counts): void
    {
        $index = array_key_last($this->batches);
        if (null === $index) {
            return;
        }

        $ids = $this->batches[$index]['nickIds'];
        $normalized = array_fill_keys($ids, 0);
        foreach ($counts as $nickId => $count) {
            if (array_key_exists($nickId, $normalized)) {
                $normalized[$nickId] = $count;
            }
        }
        $this->batches[$index]['counts'] = $normalized;
        $this->batches[$index]['valid'] = true;
    }

    /** Mutations invalidate every active frame so a nested batch cannot restore stale counts. */
    public function invalidateCounts(): void
    {
        foreach ($this->batches as $index => $batch) {
            $this->batches[$index]['valid'] = false;
        }
    }

    public function finishBatch(object $token): void
    {
        foreach ($this->batches as $index => $batch) {
            if ($batch['token'] === $token) {
                array_splice($this->batches, $index, 1);

                return;
            }
        }
    }
}
