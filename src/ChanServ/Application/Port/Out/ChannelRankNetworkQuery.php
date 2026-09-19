<?php

declare(strict_types=1);

namespace App\ChanServ\Application\Port\Out;

use App\ChanServ\Application\Model\ChannelRankNetworkState;

interface ChannelRankNetworkQuery
{
    public function findChannel(string $channelName): ?ChannelRankNetworkState;
}
