<?php

declare(strict_types=1);

namespace App\CRegServ\Application\Port\Out;

/**
 * Lets CReG ask ChanServ whether a channel name is already registered.
 *
 * The channel registration table belongs to ChanServ, so CReG reads it through this port and
 * delegates to the ChanServ repository in the adapter. Application must not reference ChanServ.
 */
interface RegisteredChannelLookupPort
{
    public function existsByChannelName(string $channelName): bool;
}
