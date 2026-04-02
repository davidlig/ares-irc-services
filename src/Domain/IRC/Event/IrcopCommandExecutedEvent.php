<?php

declare(strict_types=1);

namespace App\Domain\IRC\Event;

use Symfony\Contracts\EventDispatcher\Event;

final class IrcopCommandExecutedEvent extends Event
{
    public function __construct(
        public readonly string $operatorNick,
        public readonly string $commandName,
        public readonly string $permission,
        public readonly ?string $target = null,
        public readonly ?string $targetHost = null,
        public readonly ?string $targetIp = null,
        public readonly ?string $reason = null,
        public readonly array $extra = [],
    ) {
    }
}
