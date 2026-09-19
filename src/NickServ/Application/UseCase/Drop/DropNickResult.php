<?php

declare(strict_types=1);

namespace App\NickServ\Application\UseCase\Drop;

final readonly class DropNickResult
{
    private function __construct(
        public DropNickOutcome $outcome,
        public ?string $nickname = null,
    ) {}

    public static function cannotDropSelf(): self
    {
        return new self(DropNickOutcome::CannotDropSelf);
    }

    public static function notRegistered(string $nickname): self
    {
        return new self(DropNickOutcome::NotRegistered, nickname: $nickname);
    }

    public static function pendingDeletion(string $nickname): self
    {
        return new self(DropNickOutcome::PendingDeletion, nickname: $nickname);
    }

    public static function forcePermissionDenied(): self
    {
        return new self(DropNickOutcome::ForcePermissionDenied);
    }

    public static function suspended(string $nickname): self
    {
        return new self(DropNickOutcome::Suspended, nickname: $nickname);
    }

    public static function forbidden(string $nickname): self
    {
        return new self(DropNickOutcome::Forbidden, nickname: $nickname);
    }

    public static function cannotDropRoot(string $nickname): self
    {
        return new self(DropNickOutcome::CannotDropRoot, nickname: $nickname);
    }

    public static function cannotDropOper(string $nickname): self
    {
        return new self(DropNickOutcome::CannotDropOper, nickname: $nickname);
    }

    public static function cannotDropService(string $nickname): self
    {
        return new self(DropNickOutcome::CannotDropService, nickname: $nickname);
    }

    public static function softDropSuccess(string $nickname): self
    {
        return new self(DropNickOutcome::SoftDropSuccess, nickname: $nickname);
    }

    public static function hardDropSuccess(string $nickname): self
    {
        return new self(DropNickOutcome::HardDropSuccess, nickname: $nickname);
    }
}
