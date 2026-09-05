<?php

declare(strict_types=1);

namespace App\Domain\NickServ\Event;

use DateTimeImmutable;

/** Dispatched inside the database transaction exclusively for persistent cleanup. */
final readonly class NickDropCleanupEvent
{
    public function __construct(
        public int $nickId,
        public string $nickname,
        public string $nicknameLower,
        public string $reason,
        public DateTimeImmutable $occurredAt = new DateTimeImmutable(),
    ) {}
}
