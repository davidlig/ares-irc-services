<?php

declare(strict_types=1);

namespace App\ChanServ\Application\UseCase\ManageAkick;

enum ManageChannelAkickOutcome
{
    case ActorNotAuthenticated;
    case MissingSender;
    case InvalidRequest;
    case UnknownAction;
    case InvalidMask;
    case DangerousMask;
    case ProtectedUser;
    case DuplicateActive;
    case LimitReached;
    case ListEmpty;
    case Listed;
    case Added;
    case Deleted;
    case EntryNotFound;
}
