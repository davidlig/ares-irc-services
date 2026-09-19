<?php

declare(strict_types=1);

namespace App\MemoServ\Application\UseCase\Read;

enum ReadMemoOutcome
{
    case Success;
    case ChannelNotRegistered;
    case AccessDenied;
    case NotFound;
}
