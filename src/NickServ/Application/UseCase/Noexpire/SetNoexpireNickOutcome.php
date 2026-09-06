<?php

declare(strict_types=1);

namespace App\NickServ\Application\UseCase\Noexpire;

enum SetNoexpireNickOutcome
{
    case NotRegistered;
    case Forbidden;
    case Suspended;
    case Success;
}
