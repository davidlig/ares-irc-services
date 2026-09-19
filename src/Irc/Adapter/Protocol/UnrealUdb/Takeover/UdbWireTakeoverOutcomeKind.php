<?php

declare(strict_types=1);

namespace App\Irc\Adapter\Protocol\UnrealUdb\Takeover;

enum UdbWireTakeoverOutcomeKind
{
    case Ignored;
    case Request;
    case Acknowledge;
    case Error;
}
