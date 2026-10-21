<?php

declare(strict_types=1);

namespace App\NickServ\Application\UseCase\Recover;

enum RecoverNickOutcome
{
    case NotRegistered;
    case Pending;
    case Suspended;
    case Forbidden;
    case Instructions;
    case CredentialUnavailable;
    case PendingDeletion;
    case Throttled;
    case InvalidToken;
    case PasswordReset;
    case TokenSent;
    case NoEmail;
    case MailDeliveryFailed;
    case TemporarilyUnavailable;
}
