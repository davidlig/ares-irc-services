<?php

declare(strict_types=1);

namespace App\Application\OperServ\Security;

use App\Application\Security\PermissionProviderInterface;

final readonly class OperServIrcopPermission implements PermissionProviderInterface
{
    public function getServiceName(): string
    {
        return 'OperServ';
    }

    public function getPermissions(): array
    {
        return [];
    }
}
