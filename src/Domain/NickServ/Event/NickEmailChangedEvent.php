<?php

declare(strict_types=1);

namespace App\Domain\NickServ\Event;

use DateTimeImmutable;

/**
 * Dispatched when a nickname email is changed.
 * Covers SET EMAIL and SASET EMAIL.
 */
final readonly class NickEmailChangedEvent
{
    public function __construct(
        public int $nickId,
        public string $nickname,
        public ?string $oldEmail,
        public string $newEmail,
        public bool $changedByOwner,
        public string $performedBy,
        public ?int $performedByNickId,
        public string $performedByIp,
        public string $performedByHost,
        public DateTimeImmutable $occurredAt = new DateTimeImmutable(),
    ) {
    }
}
