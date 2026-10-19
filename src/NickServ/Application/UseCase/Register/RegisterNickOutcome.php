<?php

declare(strict_types=1);

namespace App\NickServ\Application\UseCase\Register;

enum RegisterNickOutcome
{
    case VerificationRequired;
    case RegisteredWithoutVerification;
    case Throttled;
    case GuestPrefixForbidden;
    case InvalidEmail;
    case EmailDomainBlocked;
    case EmailAlreadyUsed;
    case AlreadyPending;
    case Forbidden;
    case PendingDeletion;
    case AlreadyRegistered;
    case MailDeliveryFailed;
}
