<?php

declare(strict_types=1);

namespace App\MemoServ\Application\UseCase\Send;

enum SendMemoOutcome
{
    case SentToNick;
    case SentToChannel;
    case Throttled;
    case CannotSendToSelf;
    case NickNotRegistered;
    case ChannelNotRegistered;
    case Ignored;
    case LimitReached;
}
