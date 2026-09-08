<?php

declare(strict_types=1);

namespace App\ChanServ\Application\Port\Out;

use App\ChanServ\Application\Model\ChannelEntryMember;
use App\ChanServ\Application\Model\ChannelEntryNetworkState;

interface ChannelEntryNetworkQuery
{
    public function synchronizationComplete(): bool;

    public function findMember(string $uid): ?ChannelEntryMember;

    public function findChannel(string $channelName): ?ChannelEntryNetworkState;
}
