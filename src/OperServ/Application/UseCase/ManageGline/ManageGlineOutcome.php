<?php

declare(strict_types=1);

namespace App\OperServ\Application\UseCase\ManageGline;

enum ManageGlineOutcome
{
    case UnknownAction;
    case InvalidRequest;
    case InvalidMask;
    case UserNotFound;
    case GlobalMask;
    case DangerousMask;
    case ProtectedUser;
    case InvalidExpiry;
    case AlreadyExists;
    case LimitReached;
    case NotFound;
    case ListEmpty;
    case Added;
    case Deleted;
    case Listed;
}
