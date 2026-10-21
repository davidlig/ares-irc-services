<?php

declare(strict_types=1);

namespace App\ChanServ\Application\UseCase\TransferFounder;

enum FounderRescueTokenOutcome
{
    case Valid;
    case Invalid;
    case Unavailable;
    case Throttled;
}
