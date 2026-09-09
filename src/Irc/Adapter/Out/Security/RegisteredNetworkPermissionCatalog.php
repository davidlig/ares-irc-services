<?php

declare(strict_types=1);

namespace App\Irc\Adapter\Out\Security;

use App\Irc\Application\Port\In\NetworkPermissionCatalog;
use App\OperServ\Application\Port\In\OperatorPermissionCatalog;

final readonly class RegisteredNetworkPermissionCatalog implements NetworkPermissionCatalog
{
    public function __construct(private OperatorPermissionCatalog $permissions) {}

    public function all(): array
    {
        return $this->permissions->getAllPermissions();
    }
}
