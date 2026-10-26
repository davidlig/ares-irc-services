<?php

declare(strict_types=1);

namespace App\ChanServ\Application\UseCase\RegisterChannel;

enum RegisterChannelOutcome
{
    case Registered;
    case AwaitingApproval;
    case NotIdentified;
    case PendingDeletion;
    case RegistrationRestricted;
    case ChannelNotOnNetwork;
    case InsufficientChannelRank;
    case Throttled;
    case FounderLimitExceeded;
}
