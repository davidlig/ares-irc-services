<?php

declare(strict_types=1);

namespace App\Domain\IRC\Event;

use App\Domain\IRC\Server\ServerLink;
use DateTimeImmutable;

final readonly class ConnectionLostEvent
{
    public function __construct(
        public ServerLink $serverLink,
        public ?string $reason,
        public DateTimeImmutable $occurredAt = new DateTimeImmutable(),
    ) {}
}
