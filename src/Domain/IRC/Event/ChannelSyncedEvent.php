<?php

declare(strict_types=1);

namespace App\Domain\IRC\Event;

use App\Domain\IRC\Network\Channel;

/**
 * Dispatched when an SJOIN is received and creates or updates a channel
 * (typically during server burst, but also for any SJOIN that carries channel state).
 */
readonly class ChannelSyncedEvent
{
    public function __construct(public readonly Channel $channel)
    {
    }
}
