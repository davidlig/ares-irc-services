<?php

declare(strict_types=1);

namespace App\OperServ\Application\PublishedEvent;

final readonly class OperRoleForcedVhostChangedEvent
{
    public function __construct(public int $roleId, public ?string $pattern) {}
}
