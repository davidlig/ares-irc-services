<?php

declare(strict_types=1);

namespace App\Application\Port;

/**
 * Port implemented by Core: resolve a channel by name for Services.
 *
 * Services use this to obtain ChannelView (never Domain\IRC entities).
 */
interface ChannelLookupPort
{
    public function findByChannelName(string $channelName): ?ChannelView;
}
