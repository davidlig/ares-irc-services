<?php

declare(strict_types=1);

namespace App\OperServ\Application\Port\Out;

final readonly class OperatorRoleRecord
{
    /**
     * @param list<string> $permissions
     * @param list<string> $userModes
     */
    public function __construct(
        public int $id,
        public string $name,
        public string $description,
        public bool $protected,
        public array $permissions = [],
        public array $userModes = [],
        public ?string $forcedVhostPattern = null,
        public ?string $operclass = null,
    ) {}
}
