<?php

declare(strict_types=1);

namespace App\Domain\OperServ\Repository;

use App\Domain\OperServ\Entity\Gline;

interface GlineRepositoryInterface
{
    public function save(Gline $gline): void;

    public function remove(Gline $gline): void;

    public function findById(int $id): ?Gline;

    public function findByMask(string $mask): ?Gline;

    /**
     * @return Gline[] All GLINE entries, ordered by creation date
     */
    public function findAll(): array;

    /**
     * @return Gline[] All GLINE entries matching a mask pattern (LIKE search)
     */
    public function findByMaskPattern(string $pattern): array;

    /**
     * @return Gline[] All GLINE entries that have expired
     */
    public function findExpired(): array;

    /**
     * @return Gline[] All active (non-expired) GLINE entries
     */
    public function findActive(): array;

    public function countAll(): int;

    /**
     * Clear creator reference on all GLINE entries created by a nick (SET creator_nick_id = NULL).
     * Used when a nick is dropped to preserve GLINE entries but remove orphaned references.
     */
    public function clearCreatorNickId(int $nickId): void;
}
