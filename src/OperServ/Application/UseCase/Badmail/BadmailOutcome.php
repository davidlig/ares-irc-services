<?php

declare(strict_types=1);

namespace App\OperServ\Application\UseCase\Badmail;

enum BadmailOutcome
{
    case Denied;
    case Added;
    case Duplicate;
    case InvalidDomain;
    case InvalidReason;
    case Deleted;
    case NotFound;
    case InvalidId;
    case Cleared;
    case Listed;
    case InvalidPattern;
}
