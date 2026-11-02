<?php

declare(strict_types=1);

namespace App\NickServ\Application\PublishedEvent;

use App\NickServ\Application\Port\In\NickServEvent;
use DateTimeImmutable;

final readonly class NickAutomaticModesChangedEvent implements NickServEvent
{
    /**
     * @param list<string> $before
     * @param list<string> $after
     */
    public function __construct(
        public int $nickId,
        public string $nickname,
        public array $before,
        public array $after,
        public string $performedBy,
        public ?int $performedByNickId,
        public string $performedByIp,
        public string $performedByHost,
        public DateTimeImmutable $occurredAt,
    ) {}
}
