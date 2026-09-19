<?php

declare(strict_types=1);

namespace App\ChanServ\Application\UseCase\ManageHistory;

final readonly class ManageChannelHistoryResult
{
    /** @param list<ChannelHistoryEntryView> $entries */
    public function __construct(
        public ManageChannelHistoryOutcome $outcome,
        public array $entries = [],
        public int $total = 0,
        public int $start = 0,
        public int $end = 0,
        public int $totalPages = 0,
        public int $page = 1,
        public ?int $affectedEntryId = null,
    ) {}
}
