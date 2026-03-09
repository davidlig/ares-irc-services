<?php

declare(strict_types=1);

namespace App\Domain\ChanServ\Event;

use DateTimeImmutable;

/**
 * Dispatched when a registered channel is dropped (e.g. due to inactivity or manual DROP).
 * Other services (MemoServ) may subscribe to clean up memos, etc.
 * Dispatched before the channel is removed from persistence; subscribers must use event payload only.
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
    ) {
    }
}
