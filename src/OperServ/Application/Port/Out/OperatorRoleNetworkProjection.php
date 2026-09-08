<?php

declare(strict_types=1);

namespace App\OperServ\Application\Port\Out;

interface OperatorRoleNetworkProjection
{
    /**
     * @param list<string> $oldModes
     * @param list<string> $newModes
     */
    public function refreshModes(int $roleId, array $oldModes, array $newModes): void;

    public function refreshVhost(int $roleId, ?string $pattern): void;

    public function refreshOperclass(int $roleId, ?string $operclass): void;

    /** @return list<string>|null Null when the protocol lacks operclass support. */
    public function availableOperclasses(): ?array;
}
