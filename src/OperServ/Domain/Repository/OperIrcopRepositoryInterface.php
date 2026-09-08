<?php

declare(strict_types=1);

namespace App\OperServ\Domain\Repository;

use App\OperServ\Domain\Entity\OperIrcop;

interface OperIrcopRepositoryInterface
{
    public function find(int $id): ?OperIrcop;

    public function findByNickId(int $nickId): ?OperIrcop;

    /** @return OperIrcop[] */
    public function findAll(): array;

    /** @return OperIrcop[] */
    public function findByRoleId(int $roleId): array;

    public function save(OperIrcop $ircop): void;

    public function remove(OperIrcop $ircop): void;

    public function countByRoleId(int $roleId): int;

    /**
     * Delete IRCOP entry for a nick (used when nick is dropped).
     */
    public function deleteByNickId(int $nickId): void;
}
