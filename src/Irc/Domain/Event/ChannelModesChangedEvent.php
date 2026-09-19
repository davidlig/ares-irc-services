<?php

declare(strict_types=1);

namespace App\Irc\Domain\Event;

use App\Irc\Domain\Network\Channel;

/**
 * Dispatched when channel modes (or list modes) are updated from the wire
 * (e.g. MODE / FMODE / LMODE). Carries the updated channel for persistence.
 */
final readonly class ChannelModesChangedEvent
{
    public function __construct(public Channel $channel) {}
}
