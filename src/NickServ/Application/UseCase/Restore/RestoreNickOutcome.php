<?php

declare(strict_types=1);

namespace App\NickServ\Application\UseCase\Restore;

enum RestoreNickOutcome
{
    case NotRegistered;
    case NotPendingDeletion;
    case Success;
}
