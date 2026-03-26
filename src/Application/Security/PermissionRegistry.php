<?php

declare(strict_types=1);

namespace App\Application\Security;

use function array_merge;
use function sort;

final readonly class PermissionRegistry
{
    /**
     * @param iterable<PermissionProviderInterface> $providers
     */
    public function __construct(
        private iterable $providers,
    ) {
    }

    /**
     * @return array<string> All available permissions from all services, sorted alphabetically
     */
    public function getAllPermissions(): array
    {
        $permissions = [];

        foreach ($this->providers as $provider) {
            $permissions = array_merge($permissions, $provider->getPermissions());
        }

        $permissions = array_unique($permissions);
        sort($permissions);

        return $permissions;
    }

    /**
     * @return array<string, array<string>> Permissions grouped by service, sorted alphabetically
     */
    public function getPermissionsByService(): array
    {
        $byService = [];

        foreach ($this->providers as $provider) {
            $serviceName = $provider->getServiceName();
            $permissions = $provider->getPermissions();
            sort($permissions);
            $byService[$serviceName] = $permissions;
        }

        return $byService;
    }
}
