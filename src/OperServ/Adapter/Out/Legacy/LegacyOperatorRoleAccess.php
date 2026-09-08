<?php

declare(strict_types=1);

namespace App\OperServ\Adapter\Out\Legacy;

use App\Domain\OperServ\Repository\OperIrcopRepositoryInterface;
use App\Domain\OperServ\Repository\OperRoleRepositoryInterface;
use App\OperServ\Application\Port\Out\OperatorRoleAccess;

final readonly class LegacyOperatorRoleAccess implements OperatorRoleAccess
{
    public function __construct(
        private OperIrcopRepositoryInterface $operators,
        private OperRoleRepositoryInterface $roles,
    ) {}

    public function hasAssignedRole(int $accountId): bool
    {
        return null !== $this->operators->findByNickId($accountId);
    }

    public function hasPermission(int $accountId, string $permission): bool
    {
        $operator = $this->operators->findByNickId($accountId);
        if (null === $operator) {
            return false;
        }

        return $this->roles->hasPermission($operator->getRole()->getId(), $permission);
    }

    public function roleName(int $accountId): ?string
    {
        return $this->operators->findByNickId($accountId)?->getRole()->getName();
    }
}
