<?php

declare(strict_types=1);

namespace App\Application\Security;

interface PermissionProviderInterface
{
    public function getServiceName(): string;

    /**
     * @return array<string> List of permission strings (e.g., 'NICKSERV_DROP')
     */
    public function getPermissions(): array;
}
