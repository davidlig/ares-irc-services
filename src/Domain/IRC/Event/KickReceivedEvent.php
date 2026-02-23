<?php

declare(strict_types=1);

namespace App\Domain\IRC\Event;

use App\Domain\IRC\ValueObject\ChannelName;

/**
 * Raw protocol event: KICK received. Carries channel, target (UID or nick), reason.
 * Enricher resolves target and dispatches UserLeftChannelEvent.
 */
readonly class KickReceivedEvent
{
    public function __construct(
        public readonly ChannelName $channelName,
        /** Target identifier (UID or nick string). */
        public readonly string $targetId,
        public readonly string $reason,
    ) {
    }
}
