<?php

declare(strict_types=1);

namespace App\MemoServ\Application\UseCase\List;

enum ListMemosOutcome
{
    case Success;
    case Empty;
    case ChannelNotRegistered;
    case AccessDenied;
}
