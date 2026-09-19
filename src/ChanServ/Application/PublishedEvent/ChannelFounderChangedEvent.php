<?php

declare(strict_types=1);

namespace App\ChanServ\Application\PublishedEvent;

use DateTimeImmutable;

final readonly class ChannelFounderChangedEvent
{
    public function __construct(
        public int $channelId,
        public string $channelName,
        public int $oldFounderNickId,
        public int $newFounderNickId,
        public string $performedBy,
        public ?int $performedByNickId,
        public string $performedByIp,
        public string $performedByHost,
        public DateTimeImmutable $occurredAt,
        public bool $byOperator = false,
    ) {}
}
