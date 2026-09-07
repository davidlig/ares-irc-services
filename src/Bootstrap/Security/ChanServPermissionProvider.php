<?php

declare(strict_types=1);

namespace App\Bootstrap\Security;

use App\Application\Security\PermissionProviderInterface;
use App\ChanServ\Application\Security\ChanServPermission;

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
