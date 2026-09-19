<?php

declare(strict_types=1);

namespace App\ChanServ\Application\Port\Out;

use App\ChanServ\Application\Model\ChannelMlockNetworkState;

interface ChannelMlockNetworkQuery
{
    public function findChannel(string $channelName): ?ChannelMlockNetworkState;

    public function synchronizationComplete(): bool;
}
