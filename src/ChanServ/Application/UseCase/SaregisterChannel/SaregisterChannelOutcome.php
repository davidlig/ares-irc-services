<?php

declare(strict_types=1);

namespace App\ChanServ\Application\UseCase\SaregisterChannel;

enum SaregisterChannelOutcome
{
    case Registered;
    case InvalidChannelName;
    case AlreadyRegistered;
    case PendingDeletion;
    case TargetNotFound;
    case TargetNotRegistered;
    case TargetSuspended;
}
