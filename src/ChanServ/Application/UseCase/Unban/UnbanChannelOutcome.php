<?php

declare(strict_types=1);

namespace App\ChanServ\Application\UseCase\Unban;

enum UnbanChannelOutcome
{
    case Removed;
    case NoMatches;
    case ChannelNotOnNetwork;
    case Unavailable;
}
