<?php

declare(strict_types=1);

namespace App\Bootstrap\Security;

use App\Application\Security\PermissionProviderInterface;
use App\NickServ\Application\Security\NickServPermission;

final readonly class NickServPermissionProvider implements PermissionProviderInterface
{
    public function getServiceName(): string
    {
        return 'NickServ';
    }

    public function getPermissions(): array
    {
        return NickServPermission::allIrcop();
    }
}
