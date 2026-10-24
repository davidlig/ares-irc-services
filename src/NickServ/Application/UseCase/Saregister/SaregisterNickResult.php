<?php

declare(strict_types=1);

namespace App\NickServ\Application\UseCase\Saregister;

use App\NickServ\Domain\ValueObject\RegistrationMode;

final readonly class SaregisterNickResult
{
    private function __construct(
        public SaregisterNickOutcome $outcome,
        public string $nickname,
        public RegistrationMode $mode,
        public ?string $email = null,
        public ?string $token = null,
    ) {}

    public static function registered(string $nickname, RegistrationMode $mode, ?string $email): self
    {
        return new self(SaregisterNickOutcome::Registered, $nickname, $mode, $email);
    }

    public static function registeredWithToken(
        string $nickname,
        RegistrationMode $mode,
        string $token,
        ?string $email,
    ): self {
        return new self(SaregisterNickOutcome::RegisteredWithToken, $nickname, $mode, $email, $token);
    }

    public static function registeredWithoutMail(string $nickname, RegistrationMode $mode, string $email): self
    {
        return new self(SaregisterNickOutcome::RegisteredWithoutMail, $nickname, $mode, $email);
    }

    public static function invalidEmail(string $nickname, RegistrationMode $mode, ?string $email): self
    {
        return new self(SaregisterNickOutcome::InvalidEmail, $nickname, $mode, $email);
    }

    public static function emailAlreadyUsed(string $nickname, RegistrationMode $mode, string $email): self
    {
        return new self(SaregisterNickOutcome::EmailAlreadyUsed, $nickname, $mode, $email);
    }

    public static function alreadyPending(string $nickname, RegistrationMode $mode): self
    {
        return new self(SaregisterNickOutcome::AlreadyPending, $nickname, $mode);
    }

    public static function forbidden(string $nickname, RegistrationMode $mode): self
    {
        return new self(SaregisterNickOutcome::Forbidden, $nickname, $mode);
    }

    public static function pendingDeletion(string $nickname, RegistrationMode $mode): self
    {
        return new self(SaregisterNickOutcome::PendingDeletion, $nickname, $mode);
    }

    public static function alreadyRegistered(string $nickname, RegistrationMode $mode): self
    {
        return new self(SaregisterNickOutcome::AlreadyRegistered, $nickname, $mode);
    }
}
