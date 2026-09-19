<?php

declare(strict_types=1);

namespace App\NickServ\Application\UseCase\Rename;

enum RenameNickOutcome
{
    case Success;
    case NotOnline;
    case CannotRenameRoot;
    case CannotRenameOper;
    case CannotRenameService;
}
