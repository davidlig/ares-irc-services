<?php

declare(strict_types=1);

namespace App\Domain\IRC\Event;

use App\Domain\IRC\Network\Channel;

/**
 * Dispatched when channel modes (or list modes) are updated from the wire
 * (e.g. MODE / FMODE / LMODE). Carries the updated channel for persistence.
 */
readonly class ChannelModesChangedEvent
{
    public function __construct(public readonly Channel $channel)
    {
    }
}
