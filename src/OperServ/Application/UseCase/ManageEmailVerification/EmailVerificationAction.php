<?php

declare(strict_types=1);

namespace App\OperServ\Application\UseCase\ManageEmailVerification;

enum EmailVerificationAction
{
    case Enable;
    case Disable;
    case Status;
    case Unknown;
}
