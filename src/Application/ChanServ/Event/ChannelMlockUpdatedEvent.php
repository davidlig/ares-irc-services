<?php

declare(strict_types=1);

namespace App\Application\ChanServ\Event;

/**
 * Dispatched when MLOCK is turned ON or changed for a channel.
 * Listeners should enforce the new MLOCK (strip/add modes) for that channel.
 */
final readonly class ChannelMlockUpdatedEvent
{
    public function __construct(
        public string $channelName,
    ) {
    }
}
