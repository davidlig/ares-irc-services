<?php

declare(strict_types=1);

namespace App\ChanServ\Application\Port\In;

/**
 * Restores the network-side setup of a registered channel after a service join.
 */
interface RegisteredChannelSetup
{
    public function restore(string $channelName): void;
}
