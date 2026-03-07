<?php

declare(strict_types=1);

namespace App\Domain\IRC\Event;

use App\Domain\IRC\ValueObject\ChannelName;

/**
 * Raw protocol event: PART received. Carries source, channel, reason, wasKicked.
 * Enricher resolves source and dispatches UserLeftChannelEvent.
 */
final readonly class PartReceivedEvent
{
    public function __construct(
        /** Source identifier from message prefix (UID or nick string). */
        public readonly string $sourceId,
        public readonly ChannelName $channelName,
        public readonly string $reason,
        public readonly bool $wasKicked = false,
    ) {
    }
}
