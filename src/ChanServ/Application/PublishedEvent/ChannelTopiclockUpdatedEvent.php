<?php

declare(strict_types=1);

namespace App\ChanServ\Application\PublishedEvent;

use App\ChanServ\Application\Port\In\ChanServEvent;

/**
 * Dispatched when TOPICLOCK is toggled (ON/OFF) for a channel.
 */
final readonly class ChannelTopiclockUpdatedEvent implements ChanServEvent
{
    public function __construct(
        public string $channelName,
    ) {}
}
