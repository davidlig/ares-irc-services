<?php

declare(strict_types=1);

namespace App\ChanServ\Application\UseCase\ClearAccess;

final readonly class ClearChannelAccessResult
{
    public function __construct(
        public ClearChannelAccessOutcome $outcome,
        public int $removedCount = 0,
    ) {}
}
