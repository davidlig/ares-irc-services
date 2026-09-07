<?php

declare(strict_types=1);

namespace App\ChanServ\Domain\ValueObject;

enum AkickAdditionDecision: string
{
    case Add = 'add';
    case ReplaceExpired = 'replace_expired';
    case DuplicateActive = 'duplicate_active';
    case LimitReached = 'limit_reached';
}
