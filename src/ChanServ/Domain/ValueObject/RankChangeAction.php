<?php

declare(strict_types=1);

namespace App\ChanServ\Domain\ValueObject;

enum RankChangeAction: string
{
    case Grant = 'grant';
    case Revoke = 'revoke';
}
