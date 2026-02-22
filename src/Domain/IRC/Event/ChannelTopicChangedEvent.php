<?php

declare(strict_types=1);

namespace App\Domain\IRC\Event;

use App\Domain\IRC\Network\Channel;

/**
 * Dispatched when the channel topic is set or cleared from the wire
 * (e.g. TOPIC / FTOPIC). Carries the updated channel for persistence.
 */
readonly class ChannelTopicChangedEvent
{
    public function __construct(public readonly Channel $channel)
    {
    }
}
