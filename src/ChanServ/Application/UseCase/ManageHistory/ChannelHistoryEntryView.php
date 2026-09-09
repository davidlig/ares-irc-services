<?php

declare(strict_types=1);

namespace App\ChanServ\Application\UseCase\ManageHistory;

use DateTimeImmutable;

final readonly class ChannelHistoryEntryView
{
    /** @param array<string, mixed> $extraData */
    public function __construct(
        public int $id,
        public string $action,
        public string $performedBy,
        public bool $operatorAccountMissing,
        public DateTimeImmutable $performedAt,
        public string $message,
        public array $extraData,
    ) {}
}
