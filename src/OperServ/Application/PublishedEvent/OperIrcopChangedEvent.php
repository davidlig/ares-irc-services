<?php

declare(strict_types=1);

namespace App\OperServ\Application\PublishedEvent;

final readonly class OperIrcopChangedEvent
{
    public function __construct(public int $nickId, public string $nickname) {}
}
