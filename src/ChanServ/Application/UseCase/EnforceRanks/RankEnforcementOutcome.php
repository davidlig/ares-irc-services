<?php

declare(strict_types=1);

namespace App\ChanServ\Application\UseCase\EnforceRanks;

enum RankEnforcementOutcome
{
    case Applied;
    case NoChanges;
    case ChannelUnavailable;
    case ChannelBlocked;
    case MemberUnavailable;
}
