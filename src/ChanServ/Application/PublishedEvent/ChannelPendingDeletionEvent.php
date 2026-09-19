<?php

declare(strict_types=1);

namespace App\ChanServ\Application\PublishedEvent;

use DateTimeImmutable;

/**
 * Dispatched when a channel is marked pending deletion (soft DROP).
 */
final readonly class ChannelPendingDeletionEvent
{
    public function __construct(
        public int $channelId,
        public string $channelName,
        public string $channelNameLower,
        public ?string $performedBy,
        public DateTimeImmutable $occurredAt,
    ) {}
}
