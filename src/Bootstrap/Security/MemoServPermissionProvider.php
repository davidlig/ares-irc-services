<?php

declare(strict_types=1);

namespace App\Bootstrap\Security;

use App\MemoServ\Application\Security\MemoServPermission;
use App\OperServ\Application\Security\PermissionProviderInterface;

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
