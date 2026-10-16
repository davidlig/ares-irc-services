<?php

declare(strict_types=1);

namespace App\CRegServ\Application\UseCase\RequestChannelRegistration;

enum RequestChannelRegistrationOutcome
{
    case Requested;
    case InvalidChannel;
    case ChannelTooLong;
    case DescriptionTooLong;
    case AlreadyRegistered;
    case PendingDeletion;
    case DuplicateRequest;
    case RequestDenied;
    case Throttled;
}
