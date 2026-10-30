<?php

declare(strict_types=1);

namespace App\NickServ\Application\UseCase\Saregister;

final readonly class SaregisterNickResult
{
    private function __construct(
        public SaregisterNickOutcome $outcome,
        public ?string $nickname = null,
        public ?string $email = null,
        public ?string $token = null,
        public ?string $guestPrefix = null,
    ) {}

    public static function registeredToken(string $nickname, string $token): self
    {
        return new self(SaregisterNickOutcome::RegisteredToken, nickname: $nickname, token: $token);
    }

    public static function registeredEmail(string $nickname, string $email): self
    {
        return new self(SaregisterNickOutcome::RegisteredEmail, nickname: $nickname, email: $email);
    }

    public static function invalidNickname(string $nickname): self
    {
        return new self(SaregisterNickOutcome::InvalidNickname, nickname: $nickname);
    }

    public static function invalidEmail(): self
    {
        return new self(SaregisterNickOutcome::InvalidEmail);
    }

    public static function emailAlreadyUsed(string $email): self
    {
        return new self(SaregisterNickOutcome::EmailAlreadyUsed, email: $email);
    }

    public static function blockedEmailDomain(): self
    {
        return new self(SaregisterNickOutcome::BlockedEmailDomain);
    }

    public static function alreadyRegistered(string $nickname): self
    {
        return new self(SaregisterNickOutcome::AlreadyRegistered, nickname: $nickname);
    }

    public static function alreadyPending(string $nickname): self
    {
        return new self(SaregisterNickOutcome::AlreadyPending, nickname: $nickname);
    }

    public static function forbidden(string $nickname): self
    {
        return new self(SaregisterNickOutcome::Forbidden, nickname: $nickname);
    }

    public static function pendingDeletion(string $nickname): self
    {
        return new self(SaregisterNickOutcome::PendingDeletion, nickname: $nickname);
    }

    public static function guestPrefixForbidden(string $prefix): self
    {
        return new self(SaregisterNickOutcome::GuestPrefixForbidden, guestPrefix: $prefix);
    }

    public static function mailDeliveryFailed(string $nickname): self
    {
        return new self(SaregisterNickOutcome::MailDeliveryFailed, nickname: $nickname);
    }
}
