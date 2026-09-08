<?php

declare(strict_types=1);

namespace App\ChanServ\Application\UseCase\ManageAccess;

enum ManageChannelAccessOutcome
{
    case ActorNotAuthenticated;
    case UnknownAction;
    case InvalidRequest;
    case InvalidLevel;
    case ListEmpty;
    case Listed;
    case Added;
    case Deleted;
    case TargetNotRegistered;
    case FounderNotAllowed;
    case LimitReached;
    case CannotManageLevel;
    case EntryNotFound;
}
