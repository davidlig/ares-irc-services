<?php

declare(strict_types=1);

namespace App\ChanServ\Application\UseCase\ClearUsers;

final readonly class ClearChannelUsersResult
{
    public function __construct(
        public ClearChannelUsersOutcome $outcome,
        public int $kickedCount = 0,
    ) {}
}
