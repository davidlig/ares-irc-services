<?php

declare(strict_types=1);

namespace App\OperServ\Application\Port\Out;

interface OperatorPermissionCache
{
    /**
     * @return array{roleName: ?string, permissions: list<string>}|null
     */
    public function get(int $nickId): ?array;

    /**
     * @param list<string> $permissions
     */
    public function set(int $nickId, ?string $roleName, array $permissions): void;

    public function invalidate(int $nickId): void;
}
