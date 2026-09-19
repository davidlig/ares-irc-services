<?php

declare(strict_types=1);

namespace App\NickServ\Application\UseCase\Identify;

final readonly class IdentifyNickResult
{
    private function __construct(
        public IdentifyNickOutcome $outcome,
        public ?string $nickname = null,
        public ?string $reason = null,
        public int $retryAfterSeconds = 0,
        public ?string $displayVhost = null,
        public ?string $accountLanguage = null,
    ) {}

    public static function success(
        string $nickname,
        ?string $displayVhost = null,
        ?string $accountLanguage = null,
    ): self {
        return new self(
            IdentifyNickOutcome::Success,
            nickname: $nickname,
            displayVhost: $displayVhost,
            accountLanguage: $accountLanguage,
        );
    }

    public static function alreadyIdentified(string $nickname): self
    {
        return new self(IdentifyNickOutcome::AlreadyIdentified, nickname: $nickname);
    }

    public static function lockedOut(int $retryAfterSeconds): self
    {
        return new self(IdentifyNickOutcome::LockedOut, retryAfterSeconds: $retryAfterSeconds);
    }

    public static function notRegistered(string $nickname): self
    {
        return new self(IdentifyNickOutcome::NotRegistered, nickname: $nickname);
    }

    public static function pending(string $nickname): self
    {
        return new self(IdentifyNickOutcome::Pending, nickname: $nickname);
    }

    public static function suspended(string $nickname, string $reason): self
    {
        return new self(IdentifyNickOutcome::Suspended, nickname: $nickname, reason: $reason);
    }

    public static function forbidden(string $nickname): self
    {
        return new self(IdentifyNickOutcome::Forbidden, nickname: $nickname);
    }

    public static function pendingDeletion(string $nickname): self
    {
        return new self(IdentifyNickOutcome::PendingDeletion, nickname: $nickname);
    }

    public static function invalidCredentials(): self
    {
        return new self(IdentifyNickOutcome::InvalidCredentials);
    }
}
