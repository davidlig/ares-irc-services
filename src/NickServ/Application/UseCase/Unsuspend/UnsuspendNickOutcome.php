<?php

declare(strict_types=1);

namespace App\NickServ\Application\UseCase\Unsuspend;

enum UnsuspendNickOutcome
{
    case Unsuspended;
    case NotRegistered;
    case NotSuspended;
}
