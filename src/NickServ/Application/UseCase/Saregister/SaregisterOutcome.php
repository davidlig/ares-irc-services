<?php

declare(strict_types=1);

namespace App\NickServ\Application\UseCase\Saregister;

enum SaregisterOutcome
{
    case Registered;
    case InvalidEmail;
    case EmailAlreadyUsed;
    case AlreadyRegistered;
    case AlreadyPending;
    case Forbidden;
    case PendingDeletion;
}
