<?php

declare(strict_types=1);

namespace App\NickServ\Application\UseCase\Saregister;

enum SaregisterNickOutcome
{
    case Registered;
    case RegisteredWithToken;
    case RegisteredWithoutMail;
    case InvalidEmail;
    case EmailAlreadyUsed;
    case AlreadyPending;
    case Forbidden;
    case PendingDeletion;
    case AlreadyRegistered;
}
