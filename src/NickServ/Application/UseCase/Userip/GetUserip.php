<?php

declare(strict_types=1);

namespace App\NickServ\Application\UseCase\Userip;

final readonly class GetUserip
{
    public function __construct(
        public string $nickname,
        public ?string $ip = null,
        public ?string $hostname = null,
    ) {}
}
