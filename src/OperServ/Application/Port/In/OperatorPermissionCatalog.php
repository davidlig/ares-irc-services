<?php

declare(strict_types=1);

namespace App\OperServ\Application\Port\In;

interface OperatorPermissionCatalog
{
    /** @return list<string> */
    public function getAllPermissions(): array;
}
