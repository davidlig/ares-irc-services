<?php

declare(strict_types=1);

namespace App\ChanServ\Adapter\Out\NickServ;

use App\ChanServ\Application\Port\Out\FounderRescueTokenValidator;
use App\ChanServ\Application\UseCase\TransferFounder\FounderRescueTokenOutcome;
use App\ChanServ\Application\UseCase\TransferFounder\FounderRescueTokenResult;
use App\NickServ\Application\Port\In\RescueTokenValidation;
use App\NickServ\Application\Port\In\RescueTokenValidationOutcome;
use App\NickServ\Application\Port\In\RescueTokenValidator;

final readonly class FounderRescueTokenValidatorAdapter implements FounderRescueTokenValidator
{
    public function __construct(private RescueTokenValidator $validator) {}

    public function validate(int $founderAccountId, string $token, string $clientKey): FounderRescueTokenResult
    {
        $result = $this->validator->validate(new RescueTokenValidation($founderAccountId, $token, $clientKey));

        return new FounderRescueTokenResult(match ($result->outcome) {
            RescueTokenValidationOutcome::Valid => FounderRescueTokenOutcome::Valid,
            RescueTokenValidationOutcome::Invalid => FounderRescueTokenOutcome::Invalid,
            RescueTokenValidationOutcome::Unavailable => FounderRescueTokenOutcome::Unavailable,
            RescueTokenValidationOutcome::Throttled => FounderRescueTokenOutcome::Throttled,
        }, $result->retryAfterSeconds);
    }
}
