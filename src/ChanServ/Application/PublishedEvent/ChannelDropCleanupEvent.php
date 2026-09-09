<?php

declare(strict_types=1);

namespace App\ChanServ\Application\PublishedEvent;

use DateTimeImmutable;

/** Dispatched inside the database transaction exclusively for persistent cleanup. */
final readonly class ChannelDropCleanupEvent
{
    public function __construct(
        public int $channelId,
        public DateTimeImmutable $occurredAt,
        public string $channelName = '',
        public string $channelNameLower = '',
        public string $reason = '',
    ) {}
}
