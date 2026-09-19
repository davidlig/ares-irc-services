<?php

declare(strict_types=1);

namespace App\Irc\Adapter\Event;

use App\Irc\Domain\Server\ServerLink;
use DateTimeImmutable;

final readonly class ConnectionEstablishedEvent
{
    public function __construct(
        public ServerLink $serverLink,
        public DateTimeImmutable $occurredAt = new DateTimeImmutable(),
    ) {}
}
