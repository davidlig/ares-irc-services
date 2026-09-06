<?php

declare(strict_types=1);

namespace App\Irc\Domain\Event;

use App\Irc\Domain\Network\Channel;

/**
 * Dispatched when the channel topic is set or cleared from the wire
 * (e.g. TOPIC / FTOPIC). Carries the updated channel for persistence.
 */
final readonly class ChannelTopicChangedEvent
{
    public function __construct(public Channel $channel) {}
}
