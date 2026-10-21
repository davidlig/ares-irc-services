<?php

declare(strict_types=1);

namespace App\ChanServ\Application\Port\Out;

use App\ChanServ\Application\UseCase\TransferFounder\FounderRescueTokenResult;

interface FounderRescueTokenValidator
{
    public function validate(int $founderAccountId, string $token, string $clientKey): FounderRescueTokenResult;
}
