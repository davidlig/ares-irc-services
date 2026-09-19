<?php

declare(strict_types=1);

namespace App\NickServ\Application\UseCase\Userip;

final readonly class GetUseripResult
{
    private function __construct(
        public GetUseripOutcome $outcome,
        public ?string $nickname = null,
        public ?string $ip = null,
        public ?string $host = null,
    ) {}

    public static function notOnline(string $nickname): self
    {
        return new self(GetUseripOutcome::NotOnline, nickname: $nickname);
    }

    public static function success(string $nickname, string $ip, string $host): self
    {
        return new self(GetUseripOutcome::Success, nickname: $nickname, ip: $ip, host: $host);
    }
}
