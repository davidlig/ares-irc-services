<?php

declare(strict_types=1);

namespace App\OperServ\Application\UseCase\ManageRole;

use DateTimeImmutable;

final readonly class ManageRole
{
    /** @param list<string> $values */
    public function __construct(
        public RoleAction $action,
        public string $actorNickname,
        public DateTimeImmutable $occurredAt,
        public string $roleName = '',
        public string $value = '',
        public array $values = [],
        public string $description = '',
    ) {}
}
