<?php

declare(strict_types=1);

namespace App\Application\ChanServ\Event;

/**
 * Dispatched when a channel founder change is completed (token consumed).
 * Listeners may re-sync user modes (q, a, o, h, v) for that channel only.
 */
final readonly class ChannelFounderChangedEvent
{
    public function __construct(
        public string $channelName,
    ) {
    }
}
