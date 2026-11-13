<?php

declare(strict_types=1);

namespace App\OperServ\Application\Port\Out;

use DateTimeImmutable;

interface GlineRepository
{
    public function findByMask(string $mask): ?GlineEntry;

    /** @return list<GlineEntry> */
    public function findAll(): array;

    /** Find a zero-based entry from the createdAt DESC result set. */
    public function findByIndex(int $index): ?GlineEntry;

    /** @return list<GlineEntry> */
    public function findByMaskPattern(string $pattern): array;

    /** @return list<GlineEntry> */
    public function findActiveAt(DateTimeImmutable $at): array;

    /** @return list<GlineEntry> */
    public function findExpiredAt(DateTimeImmutable $at): array;

    public function countAll(): int;

    public function save(string $mask, ?int $creatorAccountId, string $reason, DateTimeImmutable $createdAt, ?DateTimeImmutable $expiresAt): void;

    public function remove(GlineEntry $entry): void;

    /** @param list<int> $ids */
    public function deleteByIds(array $ids): int;

    public function clearCreatorAccountId(int $accountId): void;
}
