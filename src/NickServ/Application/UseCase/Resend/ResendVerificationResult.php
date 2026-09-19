<?php

declare(strict_types=1);

namespace App\NickServ\Application\UseCase\Resend;

final readonly class ResendVerificationResult
{
    private function __construct(
        public ResendVerificationOutcome $outcome,
        public ?string $email = null,
        public int $retryAfterSeconds = 0,
    ) {}

    public static function success(string $email): self
    {
        return new self(ResendVerificationOutcome::Success, email: $email);
    }

    public static function noPending(): self
    {
        return new self(ResendVerificationOutcome::NoPending);
    }

    public static function throttled(int $retryAfterSeconds): self
    {
        return new self(ResendVerificationOutcome::Throttled, retryAfterSeconds: $retryAfterSeconds);
    }

    public static function mailDeliveryFailed(): self
    {
        return new self(ResendVerificationOutcome::MailDeliveryFailed);
    }
}
