<?php

declare(strict_types=1);

namespace App\CRegServ\Application\UseCase\DenyChannelRegistration;

enum DenyChannelRegistrationOutcome
{
    case Denied;
    case InvalidChannel;
    case NoPendingRequest;
    case AlreadyApproved;
    case AlreadyDenied;
}
