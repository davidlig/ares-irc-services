<?php

declare(strict_types=1);

namespace App\ChanServ\Domain\ValueObject;

enum FounderTransferDecision: string
{
    case Allowed = 'allowed';
    case TargetSuspended = 'target_suspended';
    case TargetNotRegistered = 'target_not_registered';
    case SameFounder = 'same_founder';
    case TargetIsSuccessor = 'target_is_successor';
    case ChannelLimitReached = 'channel_limit_reached';
}
