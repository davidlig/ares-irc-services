<?php

declare(strict_types=1);

namespace App\NickServ\Application\UseCase\Info;

use App\NickServ\Application\Port\Out\AssociatedChannel;
use App\NickServ\Domain\ValueObject\NickStatus;
use DateTimeImmutable;

final readonly class InfoNickResult
{
    /**
     * @param list<AssociatedChannel> $channels
     */
    private function __construct(
        public InfoNickOutcome $outcome,
        public string $nickname,
        public ?string $reason = null,
        public ?DateTimeImmutable $pendingDeletionAt = null,
        public ?DateTimeImmutable $deletionExpiresAt = null,
        public ?NickStatus $status = null,
        public ?string $suspendedReason = null,
        public ?DateTimeImmutable $suspendedUntil = null,
        public ?DateTimeImmutable $registeredAt = null,
        public bool $lastSeenOnline = false,
        public ?DateTimeImmutable $lastSeenAt = null,
        public ?string $lastQuitMessage = null,
        public ?string $lastConnectIp = null,
        public ?string $lastConnectHost = null,
        public ?string $language = null,
        public ?string $email = null,
        public string $displayVhost = '',
        public bool $isNoExpire = false,
        public bool $isOwnerIdentified = false,
        public array $channels = [],
    ) {}

    public static function notRegistered(string $nickname): self
    {
        return new self(InfoNickOutcome::NotRegistered, nickname: $nickname);
    }

    public static function forbidden(string $nickname, ?string $reason = null): self
    {
        return new self(InfoNickOutcome::Forbidden, nickname: $nickname, reason: $reason);
    }

    public static function pendingDeletion(
        string $nickname,
        ?DateTimeImmutable $pendingDeletionAt = null,
        ?DateTimeImmutable $deletionExpiresAt = null,
    ): self {
        return new self(
            InfoNickOutcome::PendingDeletion,
            nickname: $nickname,
            pendingDeletionAt: $pendingDeletionAt,
            deletionExpiresAt: $deletionExpiresAt,
        );
    }

    public static function private(string $nickname): self
    {
        return new self(InfoNickOutcome::Private, nickname: $nickname);
    }

    /**
     * @param list<AssociatedChannel> $channels
     */
    public static function visible(
        string $nickname,
        NickStatus $status,
        ?string $suspendedReason,
        ?DateTimeImmutable $suspendedUntil,
        ?DateTimeImmutable $registeredAt,
        bool $lastSeenOnline,
        ?DateTimeImmutable $lastSeenAt,
        ?string $lastQuitMessage,
        ?string $lastConnectIp,
        ?string $lastConnectHost,
        string $language,
        ?string $email,
        string $displayVhost,
        bool $isNoExpire,
        bool $isOwnerIdentified,
        array $channels = [],
    ): self {
        return new self(
            InfoNickOutcome::Visible,
            nickname: $nickname,
            status: $status,
            suspendedReason: $suspendedReason,
            suspendedUntil: $suspendedUntil,
            registeredAt: $registeredAt,
            lastSeenOnline: $lastSeenOnline,
            lastSeenAt: $lastSeenAt,
            lastQuitMessage: $lastQuitMessage,
            lastConnectIp: $lastConnectIp,
            lastConnectHost: $lastConnectHost,
            language: $language,
            email: $email,
            displayVhost: $displayVhost,
            isNoExpire: $isNoExpire,
            isOwnerIdentified: $isOwnerIdentified,
            channels: $channels,
        );
    }
}
