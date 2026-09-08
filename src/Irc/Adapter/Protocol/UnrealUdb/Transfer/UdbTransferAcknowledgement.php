<?php

declare(strict_types=1);

namespace App\Irc\Adapter\Protocol\UnrealUdb\Transfer;

enum UdbTransferAcknowledgement
{
    case Accepted;
    case Unknown;
    case Mismatched;
}
