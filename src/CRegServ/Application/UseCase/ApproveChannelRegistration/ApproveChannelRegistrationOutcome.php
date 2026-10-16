<?php

declare(strict_types=1);

namespace App\CRegServ\Application\UseCase\ApproveChannelRegistration;

enum ApproveChannelRegistrationOutcome
{
    case Approved;
    case InvalidChannel;
    case NoPendingRequest;
    case AlreadyRegisteredElsewhere;
    case AlreadyApproved;
    case AlreadyDenied;
    case RegistrationFailed;
}
