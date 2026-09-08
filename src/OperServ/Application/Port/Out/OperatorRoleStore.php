<?php

declare(strict_types=1);

namespace App\OperServ\Application\Port\Out;

interface OperatorRoleStore
{
    public function findByName(string $name): ?OperatorRoleRecord;

    /** @return list<OperatorRoleRecord> */
    public function all(): array;

    public function create(string $name, string $description): OperatorRoleRecord;

    public function remove(string $name): void;

    /** @param list<string> $permissions */
    public function setPermissions(string $roleName, array $permissions): void;

    /** @param list<string> $modes */
    public function setUserModes(string $roleName, array $modes): void;

    public function setForcedVhostPattern(string $roleName, ?string $pattern): void;

    public function setOperclass(string $roleName, ?string $operclass): void;
}
