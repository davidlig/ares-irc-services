<?php

declare(strict_types=1);

namespace App\NickServ\Application\UseCase\Restore;

final readonly class RestoreNickResult
{
    private function __construct(
        public RestoreNickOutcome $outcome,
        public ?string $nickname = null,
    ) {}

    public static function notRegistered(string $nickname): self
    {
        return new self(RestoreNickOutcome::NotRegistered, nickname: $nickname);
    }

    public static function notPendingDeletion(string $nickname): self
    {
        return new self(RestoreNickOutcome::NotPendingDeletion, nickname: $nickname);
    }

    public static function success(string $nickname): self
    {
        return new self(RestoreNickOutcome::Success, nickname: $nickname);
    }
}
