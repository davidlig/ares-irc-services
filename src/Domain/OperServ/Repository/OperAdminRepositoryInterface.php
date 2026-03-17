<?php

declare(strict_types=1);

namespace App\Domain\OperServ\Repository;

use App\Domain\OperServ\Entity\OperAdmin;

interface OperAdminRepositoryInterface
{
    public function find(int $id): ?OperAdmin;

    public function findByNickId(int $nickId): ?OperAdmin;

    /** @return OperAdmin[] */
    public function findAll(): array;

    /** @return OperAdmin[] */
    public function findByRoleId(int $roleId): array;

    public function save(OperAdmin $admin): void;

    public function remove(OperAdmin $admin): void;

    public function countByRoleId(int $roleId): int;
}
