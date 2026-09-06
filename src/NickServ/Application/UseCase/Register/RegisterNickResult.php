<?php

declare(strict_types=1);

namespace App\NickServ\Application\UseCase\Register;

final readonly class RegisterNickResult
{
    private function __construct(
        public RegisterNickOutcome $outcome,
        public ?string $nickname = null,
        public ?string $email = null,
        public ?string $guestPrefix = null,
        public int $retryAfterSeconds = 0,
    ) {}

    public static function verificationRequired(string $email): self
    {
        return new self(RegisterNickOutcome::VerificationRequired, email: $email);
    }

    public static function throttled(int $retryAfterSeconds): self
    {
        return new self(RegisterNickOutcome::Throttled, retryAfterSeconds: $retryAfterSeconds);
    }

    public static function guestPrefixForbidden(string $prefix): self
    {
        return new self(RegisterNickOutcome::GuestPrefixForbidden, guestPrefix: $prefix);
    }

    public static function invalidEmail(): self
    {
        return new self(RegisterNickOutcome::InvalidEmail);
    }

    public static function emailAlreadyUsed(string $email): self
    {
        return new self(RegisterNickOutcome::EmailAlreadyUsed, email: $email);
    }

    public static function alreadyPending(string $nickname): self
    {
        return new self(RegisterNickOutcome::AlreadyPending, nickname: $nickname);
    }

    public static function forbidden(string $nickname): self
    {
        return new self(RegisterNickOutcome::Forbidden, nickname: $nickname);
    }

    public static function pendingDeletion(string $nickname): self
    {
        return new self(RegisterNickOutcome::PendingDeletion, nickname: $nickname);
    }

    public static function alreadyRegistered(string $nickname): self
    {
        return new self(RegisterNickOutcome::AlreadyRegistered, nickname: $nickname);
    }

    public static function mailDeliveryFailed(): self
    {
        return new self(RegisterNickOutcome::MailDeliveryFailed);
    }
}
