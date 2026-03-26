<?php

declare(strict_types=1);

namespace App\Application\OperServ\Security;

use App\Application\Security\PermissionProviderInterface;
use App\Domain\OperServ\Entity\OperPermission;

final readonly class OperServIrcopPermission implements PermissionProviderInterface
{
    public function getServiceName(): string
    {
        return 'OperServ';
    }

    public function getPermissions(): array
    {
        return [
            OperPermission::ADMIN_ADD,
            OperPermission::ADMIN_DEL,
            OperPermission::ADMIN_LIST,
            OperPermission::ROLE_MANAGE,
            OperPermission::PERMISSION_MANAGE,
            OperPermission::KILL_LOCAL,
            OperPermission::KILL_GLOBAL,
            OperPermission::KLINE_ADD,
            OperPermission::KLINE_DEL,
            OperPermission::KLINE_LIST,
            OperPermission::GLINE_ADD,
            OperPermission::GLINE_DEL,
            OperPermission::GLINE_LIST,
            OperPermission::USERINFO,
            OperPermission::CHANNELINFO,
            OperPermission::NETWORK_VIEW,
        ];
    }
}
