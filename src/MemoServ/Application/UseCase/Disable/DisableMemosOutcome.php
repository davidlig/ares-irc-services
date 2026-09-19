<?php

declare(strict_types=1);

namespace App\MemoServ\Application\UseCase\Disable;

enum DisableMemosOutcome
{
    case DisabledNick;
    case DisabledChannel;
    case AlreadyDisabledNick;
    case AlreadyDisabledChannel;
    case ChannelNotRegistered;
    case FounderOnly;
}
