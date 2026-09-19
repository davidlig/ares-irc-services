<?php

declare(strict_types=1);

namespace App\Irc\Adapter\Protocol;

enum RawCommandInterceptionOutcome
{
    case NotHandled;
    case Executed;
    case Rejected;
}
