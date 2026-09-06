<?php

declare(strict_types=1);

namespace App\NickServ\Application\UseCase\Resend;

enum ResendVerificationOutcome
{
    case Success;
    case NoPending;
    case Throttled;
    case MailDeliveryFailed;
}
