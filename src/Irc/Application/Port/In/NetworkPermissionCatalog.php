<?php

declare(strict_types=1);

namespace App\Irc\Application\Port\In;

interface NetworkPermissionCatalog
{
    /** @return list<string> */
    public function all(): array;
}
