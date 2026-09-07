<?php

declare(strict_types=1);

namespace App\Irc\Application\PublishedEvent;

use App\Irc\Application\Port\In\Command\CommandOutcome;

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
