<?php

declare(strict_types=1);

namespace App\ChanServ\Application\Port\Out;

use App\ChanServ\Application\Model\ChanAccountView;
use App\ChanServ\Application\Model\ChannelRankNetworkState;

interface ChannelRankNetworkQuery
{
    public function findChannel(string $channelName): ?ChannelRankNetworkState;

    /** @param array<string, ChanAccountView> $accounts keyed by the IrcIdentityFold of the nickname */
    public function findChannelWithAccounts(string $channelName, array $accounts): ?ChannelRankNetworkState;

    public function findMember(string $channelName, string $uid): ?ChannelRankNetworkState;

    /** Resolve the UID of the network user on a nickname, using the network's own casemapping. */
    public function findUidByNick(string $nickname): ?string;
}
