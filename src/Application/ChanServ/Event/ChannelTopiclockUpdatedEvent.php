<?php

declare(strict_types=1);

namespace App\Application\ChanServ\Event;

/**
 * Dispatched when TOPICLOCK is toggled (ON/OFF) for a channel.
 */
final readonly class ChannelTopiclockUpdatedEvent
{
    public function __construct(
        public string $channelName,
    ) {}
}
