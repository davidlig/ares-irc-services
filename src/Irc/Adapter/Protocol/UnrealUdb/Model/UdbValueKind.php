<?php

declare(strict_types=1);

namespace App\Irc\Adapter\Protocol\UnrealUdb\Model;

enum UdbValueKind: int
{
    case None = 0;
    case String = 1;
    case Number = 2;
}
