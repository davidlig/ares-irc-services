<?php

declare(strict_types=1);

namespace App\ChanServ\Application\UseCase\EnforceRanks;

final readonly class RankEnforcementResult
{
    public function __construct(
        public RankEnforcementOutcome $outcome,
        public int $changeCount = 0,
        public bool $activityTouched = false,
    ) {}
}
