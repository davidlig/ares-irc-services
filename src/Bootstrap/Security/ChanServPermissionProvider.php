<?php

declare(strict_types=1);

namespace App\Bootstrap\Security;

use App\ChanServ\Application\Security\ChanServPermission;
use App\OperServ\Application\Security\PermissionProviderInterface;

final readonly class ChanServPermissionProvider implements PermissionProviderInterface
{
    public function getServiceName(): string
    {
        return 'ChanServ';
    }

    public function getPermissions(): array
    {
        return ChanServPermission::allIrcop();
    }
}
