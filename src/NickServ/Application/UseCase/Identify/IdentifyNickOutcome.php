<?php

declare(strict_types=1);

namespace App\NickServ\Application\UseCase\Identify;

enum IdentifyNickOutcome
{
    case Success;
    case AlreadyIdentified;
    case LockedOut;
    case NotRegistered;
    case Pending;
    case Suspended;
    case Forbidden;
    case PendingDeletion;
    case InvalidCredentials;
}
