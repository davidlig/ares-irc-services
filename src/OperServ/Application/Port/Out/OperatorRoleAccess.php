<?php

declare(strict_types=1);

namespace App\OperServ\Application\Port\Out;

interface OperatorRoleAccess
{
    public function hasAssignedRole(int $accountId): bool;

    public function hasPermission(int $accountId, string $permission): bool;

    public function roleName(int $accountId): ?string;
}
