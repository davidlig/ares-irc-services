<?php

declare(strict_types=1);

namespace App\Application\MemoServ\Security;

use App\Application\Security\PermissionProviderInterface;

final readonly class MemoServIrcopPermission implements PermissionProviderInterface
{
    public function getServiceName(): string
    {
        return 'MemoServ';
    }

    public function getPermissions(): array
    {
        return [];
    }
}
