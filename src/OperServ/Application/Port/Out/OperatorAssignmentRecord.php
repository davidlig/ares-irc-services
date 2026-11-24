<?php

declare(strict_types=1);

namespace App\OperServ\Application\Port\Out;

final readonly class OperatorAssignmentRecord
{
    public function __construct(
        public int $nickId,
        public OperatorRoleRecord $role,
        public \DateTimeImmutable $addedAt,
    ) {}
}
