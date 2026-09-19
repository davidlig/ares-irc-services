<?php

declare(strict_types=1);

namespace App\NickServ\Application\UseCase\Recover;

final readonly class RecoverNickResult
{
    private function __construct(
        public RecoverNickOutcome $outcome,
        public ?string $nickname = null,
        public ?string $email = null,
        public ?string $reason = null,
        public int $retryAfterSeconds = 0,
        public ?string $temporaryPassword = null,
    ) {}

    public static function notRegistered(string $nickname): self
    {
        return new self(RecoverNickOutcome::NotRegistered, nickname: $nickname);
    }

    public static function pending(string $nickname): self
    {
        return new self(RecoverNickOutcome::Pending, nickname: $nickname);
    }

    public static function suspended(string $nickname, string $reason): self
    {
        return new self(RecoverNickOutcome::Suspended, nickname: $nickname, reason: $reason);
    }

    public static function forbidden(string $nickname): self
    {
        return new self(RecoverNickOutcome::Forbidden, nickname: $nickname);
    }

    public static function noEmail(string $nickname): self
    {
        return new self(RecoverNickOutcome::NoEmail, nickname: $nickname);
    }

    public static function throttled(int $retryAfterSeconds): self
    {
        return new self(RecoverNickOutcome::Throttled, retryAfterSeconds: $retryAfterSeconds);
    }

    public static function mailDeliveryFailed(): self
    {
        return new self(RecoverNickOutcome::MailDeliveryFailed);
    }

    public static function tokenSent(string $email): self
    {
        return new self(RecoverNickOutcome::TokenSent, email: $email);
    }

    public static function invalidToken(string $nickname): self
    {
        return new self(RecoverNickOutcome::InvalidToken, nickname: $nickname);
    }

    public static function passwordReset(string $nickname, string $temporaryPassword): self
    {
        return new self(
            RecoverNickOutcome::PasswordReset,
            nickname: $nickname,
            temporaryPassword: $temporaryPassword,
        );
    }
}
