<?php

declare(strict_types=1);

namespace App\NickServ\Application\UseCase\Verify;

final readonly class VerifyNickResult
{
    private function __construct(
        public VerifyNickOutcome $outcome,
        public ?string $nickname = null,
        public int $retryAfterSeconds = 0,
    ) {}

    public static function credentialConfirmed(): self
    {
        return new self(VerifyNickOutcome::CredentialConfirmed);
    }

    public static function lockedOut(int $seconds): self
    {
        return new self(VerifyNickOutcome::LockedOut, retryAfterSeconds: $seconds);
    }

    public static function success(string $nickname): self
    {
        return new self(VerifyNickOutcome::Success, nickname: $nickname);
    }

    public static function successNativeAuthenticationRequired(string $nickname): self
    {
        return new self(VerifyNickOutcome::SuccessNativeAuthenticationRequired, nickname: $nickname);
    }

    public static function noPending(): self
    {
        return new self(VerifyNickOutcome::NoPending);
    }

    public static function invalidToken(): self
    {
        return new self(VerifyNickOutcome::InvalidToken);
    }
}
