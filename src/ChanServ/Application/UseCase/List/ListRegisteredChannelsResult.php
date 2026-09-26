<?php

declare(strict_types=1);

namespace App\ChanServ\Application\UseCase\List;

final readonly class ListRegisteredChannelsResult
{
    /** @param list<ListedRegisteredChannel> $entries */
    public function __construct(
        public string $pattern,
        public int $page,
        public int $pageSize,
        public int $total,
        public array $entries,
    ) {}
}
