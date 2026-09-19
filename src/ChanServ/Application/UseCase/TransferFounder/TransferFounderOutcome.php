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
    case CurrentFounderWithoutEmail = 'current_founder_without_email';
    case Throttled = 'throttled';
    case TokenSent = 'token_sent';
    case InvalidToken = 'invalid_token';
    case Updated = 'updated';
    case Ignored = 'ignored';
    case MailDeliveryFailed = 'mail_delivery_failed';
}
