<?php

declare(strict_types=1);

namespace App\Bootstrap\Security;

use App\CRegServ\Application\Security\CRegServPermission;
use App\OperServ\Application\Security\PermissionProviderInterface;

final readonly class CRegServPermissionProvider implements PermissionProviderInterface
{
    public function getServiceName(): string
    {
        return 'CReG';
    }

    public function getPermissions(): array
    {
        return CRegServPermission::allIrcop();
    }
}
