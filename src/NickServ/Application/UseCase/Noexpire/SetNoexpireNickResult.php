<?php

declare(strict_types=1);

namespace App\NickServ\Application\UseCase\Noexpire;

final readonly class SetNoexpireNickResult
{
    private function __construct(
        public SetNoexpireNickOutcome $outcome,
        public ?string $nickname = null,
        public bool $noexpire = false,
    ) {}

    public static function notRegistered(string $nickname): self
    {
        return new self(SetNoexpireNickOutcome::NotRegistered, nickname: $nickname);
    }

    public static function forbidden(string $nickname): self
    {
        return new self(SetNoexpireNickOutcome::Forbidden, nickname: $nickname);
    }

    public static function suspended(string $nickname): self
    {
        return new self(SetNoexpireNickOutcome::Suspended, nickname: $nickname);
    }

    public static function success(string $nickname, bool $noexpire): self
    {
        return new self(SetNoexpireNickOutcome::Success, nickname: $nickname, noexpire: $noexpire);
    }
}
