<?php

declare(strict_types=1);

namespace App\CRegServ\Application\UseCase\RequestChannelRegistration;

enum RequestChannelRegistrationOutcome
{
    case Requested;
    case InvalidChannel;
    case AlreadyRegistered;
    case DuplicateRequest;
    case RequestDenied;
    case Throttled;
}
