<?php

declare(strict_types=1);

namespace App\Domain\IRC\Event;

use App\Domain\IRC\Server\ServerLink;
use DateTimeImmutable;

final readonly class ConnectionEstablishedEvent
{
    public function __construct(
        public ServerLink $serverLink,
        public DateTimeImmutable $occurredAt = new DateTimeImmutable(),
    ) {}
}
