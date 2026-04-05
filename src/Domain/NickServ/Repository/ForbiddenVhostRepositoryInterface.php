<?php

declare(strict_types=1);

namespace App\Domain\NickServ\Repository;

use App\Domain\NickServ\Entity\ForbiddenVhost;

interface ForbiddenVhostRepositoryInterface
{
    public function save(ForbiddenVhost $forbiddenVhost): void;

    public function remove(ForbiddenVhost $forbiddenVhost): void;

    public function findById(int $id): ?ForbiddenVhost;

    public function findByPattern(string $pattern): ?ForbiddenVhost;

    /**
     * @return ForbiddenVhost[] All forbidden vhost patterns, ordered by creation date
     */
    public function findAll(): array;

    public function countAll(): int;

    /**
     * Clear creator reference on all forbidden vhost entries created by a nick.
     * Used when a nick is dropped to preserve entries but remove orphaned references.
     */
    public function clearCreatedByNickId(int $nickId): void;
}
