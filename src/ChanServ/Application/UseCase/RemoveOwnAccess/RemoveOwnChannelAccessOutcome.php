<?php

declare(strict_types=1);

namespace App\ChanServ\Application\UseCase\RemoveOwnAccess;

enum RemoveOwnChannelAccessOutcome
{
    case Removed;
    case NotRegistered;
    case FounderNotInAccess;
    case NotInAccess;
}
