<?php

declare(strict_types=1);

namespace App\Domain\OperServ\Repository;

use App\Domain\OperServ\Entity\OperRole;

interface OperRoleRepositoryInterface
{
    public function find(int $id): ?OperRole;

    public function findByName(string $name): ?OperRole;

    /** @return OperRole[] */
    public function findAll(): array;

    /** @return OperRole[] */
    public function findProtected(): array;

    public function save(OperRole $role): void;

    public function remove(OperRole $role): void;

    public function hasPermission(int $roleId, string $permissionName): bool;
}
