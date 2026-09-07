<?php

declare(strict_types=1);

namespace App\ChanServ\Application\PublishedEvent;

use DateTimeImmutable;

final readonly class ChannelDropEvent
{
    public function __construct(
        public int $channelId,
        public string $channelName,
        public string $channelNameLower,
        public string $reason,
        public DateTimeImmutable $occurredAt = new DateTimeImmutable(),
    ) {}
}
