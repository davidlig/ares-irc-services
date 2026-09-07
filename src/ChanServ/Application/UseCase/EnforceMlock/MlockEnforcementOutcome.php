<?php

declare(strict_types=1);

namespace App\ChanServ\Application\UseCase\EnforceMlock;

enum MlockEnforcementOutcome
{
    case Applied;
    case NoChanges;
    case ChannelUnavailable;
    case ChannelBlocked;
    case LockInactive;
    case SynchronizationPending;
}
