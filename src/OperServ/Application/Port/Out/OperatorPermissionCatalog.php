<?php

declare(strict_types=1);

namespace App\OperServ\Application\Port\Out;

interface OperatorPermissionCatalog
{
    /** @return list<string> */
    public function all(): array;
}
