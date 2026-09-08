<?php

declare(strict_types=1);

namespace App\OperServ\Application\PublishedEvent;

use DateTimeImmutable;

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
