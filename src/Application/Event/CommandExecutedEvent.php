<?php

declare(strict_types=1);

namespace App\Application\Event;

use App\Application\Command\CommandOutcome;

final readonly class CommandExecutedEvent
{
    public function __construct(
        public object $command,
        public string $serviceName,
        public string $operatorNick,
        public string $commandName,
        public ?string $permission,
        public ?CommandOutcome $outcome,
    ) {}
}
