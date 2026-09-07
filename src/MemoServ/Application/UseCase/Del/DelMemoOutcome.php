<?php

declare(strict_types=1);

namespace App\MemoServ\Application\UseCase\Del;

enum DelMemoOutcome
{
    case Deleted;
    case ChannelNotRegistered;
    case NotFound;
}
