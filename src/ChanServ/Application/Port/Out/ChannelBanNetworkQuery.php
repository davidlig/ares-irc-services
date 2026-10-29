<?php

declare(strict_types=1);

namespace App\ChanServ\Application\Port\Out;

use App\ChanServ\Application\Model\ChannelBanSnapshot;

interface ChannelBanNetworkQuery
{
    /** Null means the complete set of matching ordinary bans cannot be determined. */
    public function findMatchingBans(string $channelName, string $uid): ?ChannelBanSnapshot;
}
