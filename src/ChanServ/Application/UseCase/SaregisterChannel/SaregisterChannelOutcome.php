<?php

declare(strict_types=1);

namespace App\ChanServ\Application\UseCase\SaregisterChannel;

enum SaregisterChannelOutcome
{
    case Registered;
    case AlreadyRegistered;
    case PendingDeletion;
    case FounderNotFound;
    case FounderInactive;
}
