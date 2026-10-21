<?php

declare(strict_types=1);

namespace App\NickServ\Application\Port\In;

enum RescueTokenValidationOutcome
{
    case Valid;
    case Invalid;
    case Unavailable;
    case Throttled;
}
