<?php

declare(strict_types=1);

namespace App\NickServ\Application\UseCase\Status;

use DateTimeImmutable;

final readonly class StatusNickResult
{
    private function __construct(
        public StatusNickOutcome $outcome,
        public string $nickname,
        public ?DateTimeImmutable $expiresAt = null,
        public int $expiresInMinutes = 0,
        public ?string $reason = null,
        public ?DateTimeImmutable $suspendedUntil = null,
        public ?DateTimeImmutable $pendingDeletionAt = null,
    ) {}

    public static function unregisteredOffline(string $nickname): self
    {
        return new self(StatusNickOutcome::UnregisteredOffline, nickname: $nickname);
    }

    public static function unregisteredOnline(string $nickname): self
    {
        return new self(StatusNickOutcome::UnregisteredOnline, nickname: $nickname);
    }

    public static function pending(
        string $nickname,
        ?DateTimeImmutable $expiresAt = null,
        int $expiresInMinutes = 0,
    ): self {
        return new self(
            StatusNickOutcome::Pending,
            nickname: $nickname,
            expiresAt: $expiresAt,
            expiresInMinutes: $expiresInMinutes,
        );
    }

    public static function registeredNotConnected(string $nickname): self
    {
        return new self(StatusNickOutcome::RegisteredNotConnected, nickname: $nickname);
    }

    public static function registeredNotIdentified(string $nickname): self
    {
        return new self(StatusNickOutcome::RegisteredNotIdentified, nickname: $nickname);
    }

    public static function registeredIdentified(string $nickname): self
    {
        return new self(StatusNickOutcome::RegisteredIdentified, nickname: $nickname);
    }

    public static function suspended(
        string $nickname,
        ?string $reason = null,
        ?DateTimeImmutable $suspendedUntil = null,
    ): self {
        return new self(
            StatusNickOutcome::Suspended,
            nickname: $nickname,
            reason: $reason,
            suspendedUntil: $suspendedUntil,
        );
    }

    public static function forbidden(string $nickname, ?string $reason = null): self
    {
        return new self(
            StatusNickOutcome::Forbidden,
            nickname: $nickname,
            reason: $reason,
        );
    }

    public static function pendingDeletion(string $nickname, ?DateTimeImmutable $pendingDeletionAt = null): self
    {
        return new self(
            StatusNickOutcome::PendingDeletion,
            nickname: $nickname,
            pendingDeletionAt: $pendingDeletionAt,
        );
    }
}
