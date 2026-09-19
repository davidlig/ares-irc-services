<?php

declare(strict_types=1);

namespace App\ChanServ\Application\UseCase\ManageLevels;

final readonly class ManageChannelLevelsResult
{
    /** @param array<string, int> $levels */
    public function __construct(
        public ManageChannelLevelsOutcome $outcome,
        public array $levels = [],
        public ?string $levelKey = null,
        public ?int $value = null,
    ) {}
}
