<?php

declare(strict_types=1);

namespace App\NickServ\Application\UseCase\ForbidVhost;

enum ManageForbiddenVhostOutcome
{
    case Added;
    case Deleted;
    case Listed;
    case InvalidPattern;
    case AlreadyExists;
    case NotFound;
    case Empty;
}
