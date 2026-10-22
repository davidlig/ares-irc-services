<?php

declare(strict_types=1);

namespace App\ChanServ\Application\UseCase\TransferFounder;

enum TransferFounderOutcome: string
{
    case MissingTarget = 'missing_target';
    case TargetNotFound = 'target_not_found';
    case TargetSuspended = 'target_suspended';
    case TargetNotRegistered = 'target_not_registered';
    case SameFounder = 'same_founder';
    case TargetIsSuccessor = 'target_is_successor';
    case ChannelLimitReached = 'channel_limit_reached';
    case TokenRequired = 'token_required';
    case CredentialUnavailable = 'credential_unavailable';
    case Throttled = 'throttled';
    case InvalidToken = 'invalid_token';
    case Updated = 'updated';
    case Ignored = 'ignored';
}
