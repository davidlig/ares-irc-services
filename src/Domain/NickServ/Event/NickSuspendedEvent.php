<?php

declare(strict_types=1);

namespace App\Domain\NickServ\Event;

use DateTimeImmutable;

/**
 * Dispatched when a nickname is suspended.
 */
final readonly class NickSuspendedEvent
{
    public function __construct(
        public int $nickId,
        public string $nickname,
        public string $reason,
        public ?string $duration,
        public ?DateTimeImmutable $expiresAt,
        public string $performedBy,
        public ?int $performedByNickId,
        public string $performedByIp,
        public string $performedByHost,
        public DateTimeImmutable $occurredAt = new DateTimeImmutable(),
    ) {
    }
}
