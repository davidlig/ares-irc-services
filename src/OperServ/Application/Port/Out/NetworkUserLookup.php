<?php

declare(strict_types=1);

namespace App\OperServ\Application\Port\Out;

/** Consumer-owned view of connected IRC users needed by OperServ operations. */
interface NetworkUserLookup
{
    public function findByNickname(string $nickname): ?NetworkUser;
}
