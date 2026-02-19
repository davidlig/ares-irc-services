<?php

declare(strict_types=1);

namespace App\Domain\IRC\Event;

use App\Domain\IRC\Server\ServerLink;

readonly class ConnectionLostEvent
{
    public function __construct(
        public readonly ServerLink $serverLink,
        public readonly ?string $reason,
        public readonly \DateTimeImmutable $occurredAt = new \DateTimeImmutable(),
    ) {
    }
}
