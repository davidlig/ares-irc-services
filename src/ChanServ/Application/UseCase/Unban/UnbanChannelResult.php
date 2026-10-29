<?php

declare(strict_types=1);

namespace App\ChanServ\Application\UseCase\Unban;

final readonly class UnbanChannelResult
{
    public function __construct(public UnbanChannelOutcome $outcome, public int $removedCount = 0) {}
}
