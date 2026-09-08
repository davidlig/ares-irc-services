<?php

declare(strict_types=1);

namespace App\OperServ\Domain\Repository;

use App\OperServ\Domain\Entity\OperPermission;

interface OperPermissionRepositoryInterface
{
    public function find(int $id): ?OperPermission;

    public function findByName(string $name): ?OperPermission;

    /** @return OperPermission[] */
    public function findAll(): array;

    public function save(OperPermission $permission): void;
}
