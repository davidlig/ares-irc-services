<?php

declare(strict_types=1);

namespace App\Irc\Adapter\Protocol\UnrealUdb\Session;

use function time;

final readonly class SystemUdbClock implements UdbClock
{
    public function now(): int
    {
        return time();
    }
}
