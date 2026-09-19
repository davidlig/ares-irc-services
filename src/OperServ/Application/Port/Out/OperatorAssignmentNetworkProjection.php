<?php

declare(strict_types=1);

namespace App\OperServ\Application\Port\Out;

interface OperatorAssignmentNetworkProjection
{
    public function apply(int $nickId, string $nickname, OperatorRoleRecord $role): void;

    public function remove(int $nickId, string $nickname, OperatorRoleRecord $role): void;

    public function replace(int $nickId, string $nickname, OperatorRoleRecord $oldRole, OperatorRoleRecord $newRole): void;
}
