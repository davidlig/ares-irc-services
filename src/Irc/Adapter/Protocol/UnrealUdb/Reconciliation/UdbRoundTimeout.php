<?php

declare(strict_types=1);

namespace App\Irc\Adapter\Protocol\UnrealUdb\Reconciliation;

enum UdbRoundTimeout
{
    case None;
    case Inactivity;
    case Absolute;
}
