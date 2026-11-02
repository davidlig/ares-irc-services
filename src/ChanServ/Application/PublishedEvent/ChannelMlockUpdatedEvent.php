<?php

declare(strict_types=1);

namespace App\ChanServ\Application\PublishedEvent;

use App\ChanServ\Application\Port\In\ChanServEvent;

/**
 * Dispatched when MLOCK is turned ON or changed for a channel.
 * Listeners should enforce the new MLOCK (strip/add modes) for that channel.
 */
final readonly class ChannelMlockUpdatedEvent implements ChanServEvent
{
    public function __construct(
        public string $channelName,
    ) {}
}
