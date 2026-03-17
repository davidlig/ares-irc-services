<?php

declare(strict_types=1);

namespace App\Application\OperServ;

use App\Domain\OperServ\Entity\OperAdmin;
use App\Domain\OperServ\Repository\OperAdminRepositoryInterface;
use App\Domain\OperServ\Repository\OperRoleRepositoryInterface;

final readonly class IrcopAccessHelper
{
    public function __construct(
        private RootUserRegistry $rootUserRegistry,
        private OperAdminRepositoryInterface $adminRepository,
        private OperRoleRepositoryInterface $roleRepository,
    ) {
    }

    public function isRoot(string $nick): bool
    {
        return $this->rootUserRegistry->isRoot($nick);
    }

    public function getAdminByNickId(int $nickId): ?OperAdmin
    {
        return $this->adminRepository->findByNickId($nickId);
    }

    public function hasPermission(int $nickId, string $nickLower, string $permission): bool
    {
        if ($this->rootUserRegistry->isRoot($nickLower)) {
            return true;
        }

        $admin = $this->adminRepository->findByNickId($nickId);
        if (null === $admin) {
            return false;
        }

        return $this->roleRepository->hasPermission($admin->getRole()->getId(), $permission);
    }

    public function hasAnyPermission(int $nickId, string $nickLower, array $permissions): bool
    {
        foreach ($permissions as $permission) {
            if ($this->hasPermission($nickId, $nickLower, $permission)) {
                return true;
            }
        }

        return false;
    }

    public function getRoleName(int $nickId, string $nickLower): ?string
    {
        if ($this->rootUserRegistry->isRoot($nickLower)) {
            return 'ROOT';
        }

        $admin = $this->adminRepository->findByNickId($nickId);

        return $admin?->getRole()->getName();
    }
}
