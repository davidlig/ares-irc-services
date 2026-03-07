<?php

declare(strict_types=1);

namespace App\Domain\IRC\Event;

use App\Domain\IRC\Network\Channel;

/**
 * Dispatched when an SJOIN is received and creates or updates a channel
 * (typically during server burst, but also for any SJOIN that carries channel state).
 *
 * channelSetupApplicable: true when full channel setup (+r, MLOCK, topic) should be applied:
 * - link established (handled via NetworkSyncCompleteEvent), or
 * - channel was new or empty and now has users (first join / channel created).
 * When false (existing channel, more users joined), only SECURE/rank logic runs; no +r, modes or topic re-apply.
 */
final readonly class ChannelSyncedEvent
{
    public function __construct(
        public readonly Channel $channel,
        public readonly bool $channelSetupApplicable = true,
    ) {
    }
}
