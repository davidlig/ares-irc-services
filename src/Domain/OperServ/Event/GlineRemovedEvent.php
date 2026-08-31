<?php

declare(strict_types=1);

namespace App\Domain\OperServ\Event;

use DateTimeImmutable;

/**
 * Dispatched when a GLINE is removed (deleted by an operator or purged
 * after expiry) so UDB/IRCd projections can clean up the K-block records.
 */
final readonly class GlineRemovedEvent
{
    public function __construct(
        public int $glineId,
        public string $mask,
        public string $removedBy,
        public string $cause,
        public DateTimeImmutable $occurredAt = new DateTimeImmutable(),
    ) {}
}
