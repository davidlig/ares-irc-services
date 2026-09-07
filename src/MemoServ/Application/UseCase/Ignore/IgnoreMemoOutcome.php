<?php

declare(strict_types=1);

namespace App\MemoServ\Application\UseCase\Ignore;

enum IgnoreMemoOutcome
{
    case AddedNick;
    case AddedChannel;
    case DeletedNick;
    case DeletedChannel;
    case ListNick;
    case ListChannel;
    case ChannelNotRegistered;
    case NickNotRegistered;
    case CannotIgnoreSelf;
    case AlreadyIgnored;
    case NotIgnored;
    case LimitReached;
}
