<?php

declare(strict_types=1);

namespace App\NickServ\Application\UseCase\List;

final readonly class ListNickAccountsResult
{
    /** @param list<ListedNickAccount> $entries */
    public function __construct(
        public string $pattern,
        public int $page,
        public int $pageSize,
        public int $total,
        public array $entries,
    ) {}
}
