<?php

declare(strict_types=1);

namespace App\NickServ\Application\UseCase\Info;

enum InfoNickOutcome
{
    case NotRegistered;
    case Forbidden;
    case PendingDeletion;
    case Private;
    case Visible;
}
