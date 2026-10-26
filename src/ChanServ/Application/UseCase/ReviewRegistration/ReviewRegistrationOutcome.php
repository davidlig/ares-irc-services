<?php

declare(strict_types=1);

namespace App\ChanServ\Application\UseCase\ReviewRegistration;

enum ReviewRegistrationOutcome
{
    case Accepted;
    case Rejected;
    case NotPending;
    case ChannelNotOnNetwork;
    case FounderLimitExceeded;
    case RequesterInactive;
    case RequesterIneligible;
}
