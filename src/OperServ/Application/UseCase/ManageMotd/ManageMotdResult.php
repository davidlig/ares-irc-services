<?php

declare(strict_types=1);

namespace App\OperServ\Application\UseCase\ManageMotd;

final readonly class ManageMotdResult
{
    /** @param list<MotdListEntry> $entries */
    public function __construct(
        public ManageMotdOutcome $outcome,
        public ?int $id = null,
        public ?string $botNickname = null,
        public array $entries = [],
        public ?int $removedCount = null,
    ) {}
}
