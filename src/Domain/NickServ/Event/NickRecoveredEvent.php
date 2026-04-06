<?php

declare(strict_types=1);

namespace App\Domain\NickServ\Event;

use DateTimeImmutable;

/**
 * Dispatched when a nickname is recovered via email token.
 */
final readonly class NickRecoveredEvent
{
    public function __construct(
        public int $nickId,
        public string $nickname,
        public string $method,
        public string $performedBy,
        public ?int $performedByNickId,
        public string $performedByIp,
        public string $performedByHost,
        public DateTimeImmutable $occurredAt = new DateTimeImmutable(),
    ) {
    }
}
