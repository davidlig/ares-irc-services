<?php

declare(strict_types=1);

namespace App\OperServ\Application\Port\Out;

interface OperatorAssignmentStore
{
    public function findByNickId(int $nickId): ?OperatorAssignmentRecord;

    /** @return list<OperatorAssignmentRecord> */
    public function all(): array;

    public function assign(int $nickId, OperatorRoleRecord $role, ?int $addedById): void;

    public function remove(int $nickId): void;
}
