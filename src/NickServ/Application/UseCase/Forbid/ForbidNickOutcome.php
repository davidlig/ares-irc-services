<?php

declare(strict_types=1);

namespace App\NickServ\Application\UseCase\Forbid;

enum ForbidNickOutcome
{
    case Forbidden;
    case ReasonUpdated;
    case TargetIsRoot;
    case TargetIsIrcop;
    case TargetIsService;
}
