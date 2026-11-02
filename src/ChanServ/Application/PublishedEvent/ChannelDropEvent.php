<?php

declare(strict_types=1);

namespace App\ChanServ\Application\PublishedEvent;

use App\ChanServ\Application\Port\In\ChanServEvent;
use DateTimeImmutable;

final readonly class ChannelDropEvent implements ChanServEvent
{
    public function __construct(
        public int $channelId,
        public string $channelName,
        public string $channelNameLower,
        public string $reason,
        public DateTimeImmutable $occurredAt,
        public bool $ircopOnly = false,
    ) {}
}
