<?php

declare(strict_types=1);

namespace App\OperServ\Application\Port\In;

interface OperatorNetworkProjectionQuery
{
    public function findForNick(int $nickId, string $nickname): ?OperatorNetworkProjection;

    /** @return list<int> */
    public function findNickIdsByRoleId(int $roleId): array;
}
