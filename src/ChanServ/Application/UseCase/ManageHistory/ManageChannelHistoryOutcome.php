<?php

declare(strict_types=1);

namespace App\ChanServ\Application\UseCase\ManageHistory;

enum ManageChannelHistoryOutcome
{
    case Added;
    case Deleted;
    case Viewed;
    case Cleared;
    case ChannelNotRegistered;
    case EntryNotFound;
    case NoEntries;
}
