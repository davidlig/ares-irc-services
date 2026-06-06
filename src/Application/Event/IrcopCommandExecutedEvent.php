<?php

declare(strict_types=1);

namespace App\Application\Event;

final readonly class IrcopCommandExecutedEvent
{
    public function __construct(
        public string $serviceName,
        public string $operatorNick,
        public string $commandName,
        public string $permission,
        public ?string $target = null,
        public ?string $targetHost = null,
        public ?string $targetIp = null,
        public ?string $reason = null,
        public array $extra = [],
    ) {}
}
