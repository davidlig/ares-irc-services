<?php

declare(strict_types=1);

namespace App\ChanServ\Application\UseCase\EnforceRanks;

use App\ChanServ\Application\Model\ChannelRankNetworkState;
use App\ChanServ\Application\Model\ChannelRankPolicy;

interface EnforceChannelRanksHandlerInterface
{
    public function handle(EnforceChannelRanks $command): RankEnforcementResult;

    public function handleKnownPolicy(EnforceChannelRanks $command, ChannelRankPolicy $channel): RankEnforcementResult;

    public function handleKnownPolicyAndNetwork(EnforceChannelRanks $command, ChannelRankPolicy $channel, ?ChannelRankNetworkState $network): RankEnforcementResult;
}
