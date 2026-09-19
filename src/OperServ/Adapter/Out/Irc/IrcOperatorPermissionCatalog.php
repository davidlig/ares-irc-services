<?php

declare(strict_types=1);

namespace App\OperServ\Adapter\Out\Irc;

use App\Irc\Application\Port\In\NetworkPermissionCatalog;
use App\OperServ\Application\Port\Out\OperatorPermissionCatalog;

final readonly class IrcOperatorPermissionCatalog implements OperatorPermissionCatalog
{
    public function __construct(private NetworkPermissionCatalog $permissions) {}

    public function all(): array
    {
        return $this->permissions->all();
    }
}
