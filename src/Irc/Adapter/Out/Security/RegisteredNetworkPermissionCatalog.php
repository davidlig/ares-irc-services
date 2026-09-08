<?php

declare(strict_types=1);

namespace App\Irc\Adapter\Out\Security;

use App\Application\Security\PermissionRegistry;
use App\Irc\Application\Port\In\NetworkPermissionCatalog;

final readonly class RegisteredNetworkPermissionCatalog implements NetworkPermissionCatalog
{
    public function __construct(private PermissionRegistry $permissions) {}

    public function all(): array
    {
        return array_values($this->permissions->getAllPermissions());
    }
}
