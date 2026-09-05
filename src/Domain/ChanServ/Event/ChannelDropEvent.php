<?php

declare(strict_types=1);

namespace App\Domain\ChanServ\Event;

use DateTimeImmutable;

/**
 * Dispatched when a registered channel is dropped (e.g. due to inactivity or manual DROP).
 * Post-commit notification for external projections and effects after a channel is dropped.
 */
final readonly class ChannelDropEvent
{
    public function __construct(
        public int $channelId,
        public string $channelName,
        public string $channelNameLower,
        /** Reason for drop: e.g. 'inactivity', 'manual' */
        public string $reason,
        public DateTimeImmutable $occurredAt = new DateTimeImmutable(),
    ) {}
}
