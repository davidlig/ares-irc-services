<?php

declare(strict_types=1);

namespace App\NickServ\Application\UseCase\Rename;

final readonly class RenameNickResult
{
    private function __construct(
        public RenameNickOutcome $outcome,
        public ?string $targetNick = null,
        public ?string $targetUid = null,
        public ?string $targetHost = null,
        public ?string $targetIp = null,
        public ?string $newNick = null,
    ) {}

    public static function notOnline(string $targetNick): self
    {
        return new self(RenameNickOutcome::NotOnline, targetNick: $targetNick);
    }

    public static function cannotRenameRoot(string $targetNick): self
    {
        return new self(RenameNickOutcome::CannotRenameRoot, targetNick: $targetNick);
    }

    public static function cannotRenameOper(string $targetNick): self
    {
        return new self(RenameNickOutcome::CannotRenameOper, targetNick: $targetNick);
    }

    public static function cannotRenameService(string $targetNick): self
    {
        return new self(RenameNickOutcome::CannotRenameService, targetNick: $targetNick);
    }

    public static function success(
        string $targetNick,
        string $targetUid,
        string $targetHost,
        string $targetIp,
        string $newNick,
    ): self {
        return new self(
            RenameNickOutcome::Success,
            targetNick: $targetNick,
            targetUid: $targetUid,
            targetHost: $targetHost,
            targetIp: $targetIp,
            newNick: $newNick,
        );
    }
}
