<?php

declare(strict_types=1);

namespace App\NickServ\Application\UseCase\Saregister;

enum SaregisterNickOutcome
{
    case RegisteredToken;
    case RegisteredEmail;
    case InvalidNickname;
    case InvalidEmail;
    case EmailAlreadyUsed;
    case BlockedEmailDomain;
    case AlreadyRegistered;
    case AlreadyPending;
    case Forbidden;
    case PendingDeletion;
    case GuestPrefixForbidden;
    case MailDeliveryFailed;
}
