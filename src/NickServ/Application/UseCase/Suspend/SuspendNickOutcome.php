<?php

declare(strict_types=1);

namespace App\NickServ\Application\UseCase\Suspend;

enum SuspendNickOutcome
{
    case Suspended;
    case NotRegistered;
    case Forbidden;
    case AlreadySuspended;
    case TargetIsRoot;
    case TargetIsIrcop;
    case TargetIsService;
    case InvalidDuration;
}
