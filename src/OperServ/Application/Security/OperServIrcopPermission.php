<?php

declare(strict_types=1);

namespace App\OperServ\Application\Security;

final readonly class OperServIrcopPermission implements PermissionProviderInterface
{
    public function getServiceName(): string
    {
        return 'OperServ';
    }

    public function getPermissions(): array
    {
        return [
            OperServPermission::KILL,
            OperServPermission::GLINE,
            OperServPermission::GLOBAL,
            OperServPermission::RAW,
            OperServPermission::MOTD,
        ];
    }
}
