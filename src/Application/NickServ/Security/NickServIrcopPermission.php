<?php

declare(strict_types=1);

namespace App\Application\NickServ\Security;

use App\Application\Security\PermissionProviderInterface;

final readonly class NickServIrcopPermission implements PermissionProviderInterface
{
    public const string DROP = 'NICKSERV_DROP';

    public function getServiceName(): string
    {
        return 'NickServ';
    }

    public function getPermissions(): array
    {
        return [];
    }
}
