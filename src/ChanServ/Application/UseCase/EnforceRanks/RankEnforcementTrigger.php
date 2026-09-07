<?php

declare(strict_types=1);

namespace App\ChanServ\Application\UseCase\EnforceRanks;

enum RankEnforcementTrigger
{
    case MemberJoined;
    case MemberLeft;
    case ChannelSynchronized;
    case NetworkSynchronized;
    case LiveRankGranted;
}
