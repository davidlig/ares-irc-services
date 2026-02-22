<?php

declare(strict_types=1);

namespace App\Domain\IRC\Event;

use App\Domain\IRC\Server\ServerLink;
use DateTimeImmutable;

readonly class ConnectionEstablishedEvent
{
    public function __construct(
        public readonly ServerLink $serverLink,
        public readonly DateTimeImmutable $occurredAt = new DateTimeImmutable(),
    ) {
    }
}
