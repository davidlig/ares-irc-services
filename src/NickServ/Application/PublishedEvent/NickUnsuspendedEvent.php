<?php

declare(strict_types=1);

namespace App\NickServ\Application\PublishedEvent;

use DateTimeImmutable;

/**
 * Dispatched when a nickname suspension is lifted.
 */
final readonly class NickUnsuspendedEvent
{
    public function __construct(
        public int $nickId,
        public string $nickname,
        public string $performedBy,
        public ?int $performedByNickId,
        public string $performedByIp,
        public string $performedByHost,
        public DateTimeImmutable $occurredAt,
    ) {}
}
