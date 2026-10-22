<?php

declare(strict_types=1);

namespace App\ChanServ\Application\UseCase\TransferFounder;

final readonly class FounderRescueTokenResult
{
    public function __construct(public FounderRescueTokenOutcome $outcome, public int $retryAfterSeconds = 0) {}
}
