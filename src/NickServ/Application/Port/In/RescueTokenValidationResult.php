<?php

declare(strict_types=1);

namespace App\NickServ\Application\Port\In;

final readonly class RescueTokenValidationResult
{
    public function __construct(public RescueTokenValidationOutcome $outcome, public int $retryAfterSeconds = 0) {}
}
