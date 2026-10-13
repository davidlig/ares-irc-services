<?php

declare(strict_types=1);

namespace App\OperServ\Application\UseCase\ManageEmailVerification;

enum ManageEmailVerificationOutcome
{
    case Enabled;
    case Disabled;
    case AlreadyEnabled;
    case AlreadyDisabled;
    case StatusReported;
    case UnknownAction;
}
