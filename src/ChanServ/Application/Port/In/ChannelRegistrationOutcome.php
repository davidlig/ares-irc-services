<?php

declare(strict_types=1);

namespace App\ChanServ\Application\Port\In;

enum ChannelRegistrationOutcome
{
    case Registered;
    case PendingDeletion;
    case AlreadyRegistered;
    case Failed;
}
