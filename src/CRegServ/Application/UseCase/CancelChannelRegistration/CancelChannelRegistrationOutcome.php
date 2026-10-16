<?php

declare(strict_types=1);

namespace App\CRegServ\Application\UseCase\CancelChannelRegistration;

enum CancelChannelRegistrationOutcome
{
    case Cancelled;
    case InvalidChannel;
    case NoRequest;
    case NotOwner;
    case NotPending;
}
