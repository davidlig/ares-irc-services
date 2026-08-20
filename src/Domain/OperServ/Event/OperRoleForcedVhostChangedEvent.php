<?php

declare(strict_types=1);

namespace App\Domain\OperServ\Event;

final readonly class OperRoleForcedVhostChangedEvent
{
    public function __construct(
        public int $roleId,
        public ?string $pattern,
    ) {}
}
