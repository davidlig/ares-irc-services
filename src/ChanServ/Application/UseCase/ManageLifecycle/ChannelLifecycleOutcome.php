<?php

declare(strict_types=1);

namespace App\ChanServ\Application\UseCase\ManageLifecycle;

enum ChannelLifecycleOutcome
{
    case Dropped;
    case ForceDropped;
    case PendingDeletion;
    case NotRegistered;
    case Forbidden;
    case ForbiddenUpdated;
    case Unforbidden;
    case NotForbidden;
    case Restored;
    case NotPendingDeletion;
    case NoExpireEnabled;
    case NoExpireDisabled;
    case ChannelForbidden;
    case ChannelSuspended;
    case Suspended;
    case AlreadySuspended;
    case InvalidDuration;
    case Unsuspended;
    case NotSuspended;
}
