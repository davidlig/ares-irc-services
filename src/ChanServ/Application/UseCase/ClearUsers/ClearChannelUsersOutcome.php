<?php

declare(strict_types=1);

namespace App\ChanServ\Application\UseCase\ClearUsers;

enum ClearChannelUsersOutcome
{
    case Cleared;
    case ChannelNotRegistered;
    case ChannelNotOnNetwork;
    case AlreadyEmpty;
}
