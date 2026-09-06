<?php

declare(strict_types=1);

namespace App\NickServ\Domain\Event;

use DateTimeImmutable;

/**
 * Dispatched when a registered nickname is dropped (e.g. due to inactivity or manual DROP).
 * Post-commit notification for external projections and effects after a nickname is dropped.
 */
final readonly class NickDropEvent
{
    public function __construct(
        public int $nickId,
        public string $nickname,
        public string $nicknameLower,
        /** Reason for drop: e.g. 'inactivity', 'manual' */
        public string $reason,
        public DateTimeImmutable $occurredAt = new DateTimeImmutable(),
    ) {}
}
