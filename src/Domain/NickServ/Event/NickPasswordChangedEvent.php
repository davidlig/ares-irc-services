<?php

declare(strict_types=1);

namespace App\Domain\NickServ\Event;

use DateTimeImmutable;

/**
 * Dispatched when a nickname password is changed.
 * Covers SET PASSWORD, SASET PASSWORD, and RECOVER.
 */
final readonly class NickPasswordChangedEvent
{
    public function __construct(
        public int $nickId,
        public string $nickname,
        public bool $changedByOwner,
        public string $performedBy,
        public ?int $performedByNickId,
        public string $performedByIp,
        public string $performedByHost,
        public DateTimeImmutable $occurredAt = new DateTimeImmutable(),
    ) {
    }
}
