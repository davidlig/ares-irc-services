<?php

declare(strict_types=1);

namespace App\ChanServ\Application\UseCase\EnforceMlock;

final readonly class MlockEnforcementResult
{
    public function __construct(
        public MlockEnforcementOutcome $outcome,
        public int $changeCount = 0,
    ) {}
}
