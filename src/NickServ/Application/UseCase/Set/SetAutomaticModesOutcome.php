<?php

declare(strict_types=1);

namespace App\NickServ\Application\UseCase\Set;

enum SetAutomaticModesOutcome
{
    case Changed;
    case Unchanged;
    case InvalidModes;
    case Unavailable;
    case NotRegistered;
    case Root;
    case Ircop;
    case Service;
}
