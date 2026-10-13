<?php

declare(strict_types=1);

namespace App\NickServ\Application\UseCase\Saregister;

final readonly class SaregisterResult
{
    private function __construct(
        public SaregisterOutcome $outcome,
        public ?string $nickname = null,
        public ?string $email = null,
    ) {}

    public static function registered(string $nickname): self
    {
        return new self(SaregisterOutcome::Registered, nickname: $nickname);
    }

    public static function invalidEmail(): self
    {
        return new self(SaregisterOutcome::InvalidEmail);
    }

    public static function emailAlreadyUsed(string $email): self
    {
        return new self(SaregisterOutcome::EmailAlreadyUsed, email: $email);
    }

    public static function alreadyRegistered(string $nickname): self
    {
        return new self(SaregisterOutcome::AlreadyRegistered, nickname: $nickname);
    }

    public static function alreadyPending(string $nickname): self
    {
        return new self(SaregisterOutcome::AlreadyPending, nickname: $nickname);
    }

    public static function forbidden(string $nickname): self
    {
        return new self(SaregisterOutcome::Forbidden, nickname: $nickname);
    }

    public static function pendingDeletion(string $nickname): self
    {
        return new self(SaregisterOutcome::PendingDeletion, nickname: $nickname);
    }
}
