<?php

declare(strict_types=1);

namespace App\NickServ\Application\UseCase\RescueToken;

use App\NickServ\Application\Port\In\RescueTokenValidation;
use App\NickServ\Application\Port\In\RescueTokenValidationOutcome;
use App\NickServ\Application\Port\In\RescueTokenValidationResult;
use App\NickServ\Application\Port\In\RescueTokenValidator;
use App\NickServ\Application\Port\Out\Clock;
use App\NickServ\Application\Port\Out\RegisteredNickRepositoryInterface;
use App\NickServ\Application\Port\Out\RescueAttemptLimiter;
use App\NickServ\Application\Port\Out\RescueTokenCodec;

final readonly class ValidateRescueTokenHandler implements RescueTokenValidator
{
    public function __construct(
        private RegisteredNickRepositoryInterface $accounts,
        private RescueTokenCodec $codec,
        private RescueAttemptLimiter $limiter,
        private Clock $clock,
    ) {}

    public function validate(RescueTokenValidation $input): RescueTokenValidationResult
    {
        $now = $this->clock->now();
        $remaining = $this->limiter->remainingCooldownSeconds($input->accountId, $input->clientKey, $now);
        if (0 < $remaining) {
            return new RescueTokenValidationResult(RescueTokenValidationOutcome::Throttled, $remaining);
        }
        $account = $this->accounts->findById($input->accountId);
        if (null === $account || !$account->isRegistered() || null === $account->getRescueTokenHash()) {
            return new RescueTokenValidationResult(RescueTokenValidationOutcome::Unavailable);
        }
        if (!$this->codec->matches($input->token, $account->getRescueTokenHash())) {
            $this->limiter->recordFailure($input->accountId, $input->clientKey, $now);

            return new RescueTokenValidationResult(RescueTokenValidationOutcome::Invalid);
        }

        return new RescueTokenValidationResult(RescueTokenValidationOutcome::Valid);
    }
}
