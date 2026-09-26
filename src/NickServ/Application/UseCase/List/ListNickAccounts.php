<?php

declare(strict_types=1);

namespace App\NickServ\Application\UseCase\List;

final readonly class ListNickAccounts
{
    public function __construct(
        public string $pattern,
        public int $page = 1,
    ) {}
}
