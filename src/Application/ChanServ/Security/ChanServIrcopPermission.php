<?php

declare(strict_types=1);

namespace App\Application\ChanServ\Security;

use App\Application\Security\PermissionProviderInterface;

final readonly class ChanServIrcopPermission implements PermissionProviderInterface
{
    public function getServiceName(): string
    {
        return 'ChanServ';
    }

    public function getPermissions(): array
    {
        return [
            ChanServPermission::DROP,
            ChanServPermission::SUSPEND,
            ChanServPermission::FORBID,
            ChanServPermission::NOEXPIRE,
            ChanServPermission::LEVEL_FOUNDER,
            ChanServPermission::HISTORY,
        ];
    }
}
