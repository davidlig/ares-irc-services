<?php

declare(strict_types=1);

namespace App\ChanServ\Application\UseCase\ManageManualRank;

enum ManageManualRankOutcome
{
    case Applied;
    case NotIdentified;
    case RankNotSupported;
    case TargetNickNotRegistered;
    case TargetNotOnChannel;
    case SecureLevelRequired;
    case TargetAccessTooHigh;
}
