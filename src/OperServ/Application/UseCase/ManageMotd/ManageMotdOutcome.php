<?php

declare(strict_types=1);

namespace App\OperServ\Application\UseCase\ManageMotd;

enum ManageMotdOutcome
{
    case UnknownAction;
    case InvalidAddRequest;
    case InvalidMessageType;
    case InvalidExpiry;
    case InvalidId;
    case NotFound;
    case ListEmpty;
    case CleanEmpty;
    case Added;
    case Deleted;
    case Listed;
    case Cleaned;
}
