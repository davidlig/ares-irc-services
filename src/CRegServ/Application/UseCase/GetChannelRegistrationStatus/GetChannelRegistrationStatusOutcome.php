<?php

declare(strict_types=1);

namespace App\CRegServ\Application\UseCase\GetChannelRegistrationStatus;

enum GetChannelRegistrationStatusOutcome
{
    case Registered;
    case PendingDeletion;
    case Pending;
    case Denied;
    case NoRequest;
}
