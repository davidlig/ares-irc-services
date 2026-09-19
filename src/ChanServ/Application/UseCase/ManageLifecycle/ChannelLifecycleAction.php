<?php

declare(strict_types=1);

namespace App\ChanServ\Application\UseCase\ManageLifecycle;

enum ChannelLifecycleAction
{
    case Drop;
    case Forbid;
    case Unforbid;
    case Restore;
    case EnableNoExpire;
    case DisableNoExpire;
    case Suspend;
    case Unsuspend;
}
