<?php

declare(strict_types=1);

namespace App\MemoServ\Application\UseCase\Enable;

enum EnableMemosOutcome
{
    case EnabledNick;
    case EnabledChannel;
    case AlreadyEnabledNick;
    case AlreadyEnabledChannel;
    case ChannelNotRegistered;
    case FounderOnly;
}
