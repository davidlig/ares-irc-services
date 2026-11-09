<?php

declare(strict_types=1);

namespace App\OperServ\Adapter\Out\Projection;

use App\OperServ\Application\Port\Out\OperatorPermissionCache as OperatorPermissionCachePort;

final class OperatorPermissionCache implements OperatorPermissionCachePort
{
    /** @var array<int, array{roleName: ?string, permissions: list<string>}> */
    private array $snapshots = [];

    public function get(int $nickId): ?array
    {
        return $this->snapshots[$nickId] ?? null;
    }

    public function set(int $nickId, ?string $roleName, array $permissions): void
    {
        $this->snapshots[$nickId] = ['roleName' => $roleName, 'permissions' => $permissions];
    }

    public function invalidate(int $nickId): void
    {
        unset($this->snapshots[$nickId]);
    }
}
