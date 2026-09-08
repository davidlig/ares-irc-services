<?php

declare(strict_types=1);

namespace App\Irc\Adapter\Protocol\UnrealUdb\Session;

enum UdbPeerAdvertisementChange
{
    case Invalid;
    case SameInstance;
    case NewInstance;
}
