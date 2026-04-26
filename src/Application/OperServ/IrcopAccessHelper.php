<?php

declare(strict_types=1);

namespace App\Application\OperServ;

use App\Domain\OperServ\Entity\OperIrcop;
use App\Domain\OperServ\Repository\OperIrcopRepositoryInterface;
use App\Domain\OperServ\Repository\OperRoleRepositoryInterface;

final readonly class IrcopAccessHelper
{
    public function __construct(
        private RootUserRegistry $rootUserRegistry,
        private OperIrcopRepositoryInterface $ircopRepository,
        private OperRoleRepositoryInterface $roleRepository,
    ) {
    }

    public function isRoot(string $nick): bool
    {
        return $this->rootUserRegistry->isRoot($nick);
    }

    public function getIrcopByNickId(int $nickId): ?OperIrcop
    {
        return $this->ircopRepository->findByNickId($nickId);
    }

    public function hasPermission(int $nickId, string $nickLower, string $permission): bool
    {
        if ($this->rootUserRegistry->isRoot($nickLower)) {
            return true;
        }

        $ircop = $this->ircopRepository->findByNickId($nickId);
        if (null === $ircop) {
            return false;
        }

        return $this->roleRepository->hasPermission($ircop->getRole()->getId(), $permission);
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

    public function isIrcop(int $nickId, string $nickLower): bool
    {
        return $this->rootUserRegistry->isRoot($nickLower)
            || null !== $this->ircopRepository->findByNickId($nickId);
    }

    public function getRoleName(int $nickId, string $nickLower): ?string
    {
        if ($this->rootUserRegistry->isRoot($nickLower)) {
            return 'ROOT';
        }

        $ircop = $this->ircopRepository->findByNickId($nickId);

        return $ircop?->getRole()->getName();
    }
}
