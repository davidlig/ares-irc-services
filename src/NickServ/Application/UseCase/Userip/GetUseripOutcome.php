<?php

declare(strict_types=1);

namespace App\NickServ\Application\UseCase\Userip;

enum GetUseripOutcome
{
    case NotOnline;
    case Success;
}
