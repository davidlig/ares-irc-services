<?php

declare(strict_types=1);

namespace App\OperServ\Adapter\Out\Projection;

use App\OperServ\Application\Port\Out\OperatorPermissionCache as OperatorPermissionCachePort;
use App\OperServ\Application\Port\Out\OperatorRoleAccess;
use App\OperServ\Domain\Entity\OperPermission;
use App\OperServ\Domain\Repository\OperIrcopRepositoryInterface;

use function array_map;
use function in_array;

final readonly class DoctrineOperatorRoleAccess implements OperatorRoleAccess
{
    public function __construct(
        private OperIrcopRepositoryInterface $operators,
        private OperatorPermissionCachePort $cache,
    ) {}

    public function hasAssignedRole(int $accountId): bool
    {
        return null !== $this->snapshot($accountId)['roleName'];
    }

    public function hasPermission(int $accountId, string $permission): bool
    {
        return in_array($permission, $this->snapshot($accountId)['permissions'], true);
    }

    public function roleName(int $accountId): ?string
    {
        return $this->snapshot($accountId)['roleName'];
    }

    /** @return array{roleName: ?string, permissions: list<string>} */
    private function snapshot(int $accountId): array
    {
        $cached = $this->cache->get($accountId);
        if (null !== $cached) {
            return $cached;
        }

        $operator = $this->operators->findByNickIdWithRolePermissions($accountId);
        if (null === $operator) {
            $this->cache->set($accountId, null, []);

            return ['roleName' => null, 'permissions' => []];
        }

        $role = $operator->getRole();
        $permissions = array_map(static fn (OperPermission $permission): string => $permission->getName(), $role->getPermissions());
        $this->cache->set($accountId, $role->getName(), $permissions);

        return ['roleName' => $role->getName(), 'permissions' => $permissions];
    }
}
