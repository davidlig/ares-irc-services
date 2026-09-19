<?php

declare(strict_types=1);

namespace App\Irc\Adapter\Protocol;

enum MessageDirection
{
    case Incoming;
    case Outgoing;
}
