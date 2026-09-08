<?php

declare(strict_types=1);

namespace App\OperServ\Application\Port\Out;

interface OperatorRoleNetworkProjection
{
    public function supportsOperclass(): bool;

    /**
     * @param list<string> $oldModes
     * @param list<string> $newModes
     */
    public function refreshModes(int $roleId, array $oldModes, array $newModes): void;

    public function refreshVhost(int $roleId, ?string $pattern): void;

    public function refreshOperclass(int $roleId, ?string $operclass): void;

    /** @return list<string>|null Null when the supported protocol cannot enumerate its catalog. */
    public function availableOperclasses(): ?array;
}
