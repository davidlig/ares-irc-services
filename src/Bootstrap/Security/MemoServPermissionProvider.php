<?php

declare(strict_types=1);

namespace App\Bootstrap\Security;

use App\Application\Security\PermissionProviderInterface;
use App\MemoServ\Application\Security\MemoServPermission;

final readonly class MemoServPermissionProvider implements PermissionProviderInterface
{
    public function getServiceName(): string
    {
        return 'MemoServ';
    }

    public function getPermissions(): array
    {
        return MemoServPermission::allIrcop();
    }
}
