<?php

declare(strict_types=1);

namespace App\ChanServ\Application\UseCase\ClearAccess;

enum ClearChannelAccessOutcome
{
    case Cleared;
    case ChannelNotRegistered;
    case AlreadyEmpty;
}
