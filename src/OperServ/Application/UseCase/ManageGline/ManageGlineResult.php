<?php

declare(strict_types=1);

namespace App\OperServ\Application\UseCase\ManageGline;

final readonly class ManageGlineResult
{
    /** @param list<GlineListEntry> $entries */
    public function __construct(
        public ManageGlineOutcome $outcome,
        public ?string $mask = null,
        public ?string $protectedNickname = null,
        public ?string $expiry = null,
        public ?string $reason = null,
        public ?int $limit = null,
        public array $entries = [],
    ) {}
}
