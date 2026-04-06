<?php

declare(strict_types=1);

namespace App\Domain\NickServ\Repository;

use App\Domain\NickServ\Entity\NickHistory;
use DateTimeImmutable;

/**
 * Repository for managing nickname history entries.
 */
interface NickHistoryRepositoryInterface
{
    public function save(NickHistory $history): void;

    public function findById(int $id): ?NickHistory;

    /**
     * Find history entries for a nickname.
     *
     * @param int      $nickId The nickname ID
     * @param int|null $limit  Maximum number of entries to return (null = all)
     * @param int      $offset Number of entries to skip
     *
     * @return NickHistory[]
     */
    public function findByNickId(int $nickId, ?int $limit = null, int $offset = 0): array;

    /**
     * Count total history entries for a nickname.
     */
    public function countByNickId(int $nickId): int;

    /**
     * Delete a specific history entry by ID.
     *
     * @return bool True if entry was deleted, false if not found
     */
    public function deleteById(int $id): bool;

    /**
     * Delete all history entries for a nickname.
     * Called by NickHistoryNickDropSubscriber on NickDropEvent.
     *
     * @return int Number of entries deleted
     */
    public function deleteByNickId(int $nickId): int;

    /**
     * Delete history entries older than the given threshold.
     * Called by CleanupHistoryTask for retention policy.
     *
     * @return int Number of entries deleted
     */
    public function deleteOlderThan(DateTimeImmutable $threshold): int;
}
