<?php

declare(strict_types=1);

namespace App\NickServ\Application\UseCase\Recover;

enum RecoverNickOutcome
{
    case NotRegistered;
    case Pending;
    case Suspended;
    case Forbidden;
    case NoEmail;
    case Throttled;
    case MailDeliveryFailed;
    case TokenSent;
    case InvalidToken;
    case PasswordReset;
}
