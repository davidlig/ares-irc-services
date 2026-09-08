<?php

declare(strict_types=1);

namespace App\ChanServ\Application\UseCase\ManageLevels;

enum ManageChannelLevelsOutcome
{
    case ChannelNotRegistered;
    case ActorNotAuthenticated;
    case AccessDenied;
    case UnknownAction;
    case InvalidRequest;
    case Listed;
    case LevelSet;
    case LevelsReset;
    case UnknownLevel;
    case ValueOutOfRange;
}
