<?php

declare(strict_types=1);

namespace App\OperServ\Domain\Event;

final readonly class OperRoleForcedVhostChangedEvent
{
    public function __construct(
        public int $roleId,
        public ?string $pattern,
    ) {}
}
