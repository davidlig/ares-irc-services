<?php

declare(strict_types=1);

namespace App\ChanServ\Application\UseCase\EnforceMlock;

enum MlockEnforcementTrigger
{
    case ChannelSynchronized;
    case ChannelUnsuspended;
    case ModesChanged;
    case LockUpdated;
    case NetworkSynchronized;
}
