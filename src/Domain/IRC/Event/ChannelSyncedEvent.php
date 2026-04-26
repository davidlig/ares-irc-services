<?php

declare(strict_types=1);

namespace App\Domain\IRC\Event;

use App\Domain\IRC\Network\Channel;

/**
 * Dispatched when an SJOIN/IJOIN is received and creates or updates a channel
 * (typically during server burst, but also for any join that carries channel state).
 *
 * channelSetupApplicable: true when full channel setup was expected
 * (channel is new or was empty). Subscribers that enforce registered channel
 * policies (+r, MLOCK, TOPICLOCK, forbidden) should ALWAYS run regardless of
 * this flag — the flag is kept for informational purposes only.
 */
final readonly class ChannelSyncedEvent
{
    public function __construct(
        public readonly Channel $channel,
        public readonly bool $channelSetupApplicable = true,
    ) {
    }
}
