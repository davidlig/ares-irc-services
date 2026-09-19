<?php

declare(strict_types=1);

namespace App\NickServ\Application\UseCase\Drop;

enum DropNickOutcome
{
    case CannotDropSelf;
    case NotRegistered;
    case PendingDeletion;
    case ForcePermissionDenied;
    case Suspended;
    case Forbidden;
    case CannotDropRoot;
    case CannotDropOper;
    case CannotDropService;
    case SoftDropSuccess;
    case HardDropSuccess;
}
