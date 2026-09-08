<?php

declare(strict_types=1);

namespace App\OperServ\Application\UseCase\ManageRole;

use App\OperServ\Application\Port\Out\OperatorRoleRecord;

final readonly class ManageRoleResult
{
    /**
     * @param list<OperatorRoleRecord> $roles
     * @param list<string>             $values
     * @param list<string>             $availableValues
     */
    public function __construct(
        public RoleOutcome $outcome,
        public ?OperatorRoleRecord $role = null,
        public array $roles = [],
        public array $values = [],
        public array $availableValues = [],
        public int $count = 0,
    ) {}
}
