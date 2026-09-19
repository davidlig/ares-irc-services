<?php

declare(strict_types=1);

namespace App\Irc\Adapter\Out\Time;

use App\Irc\Application\Port\Out\AntifloodClock;

use function time;

final readonly class SystemAntifloodClock implements AntifloodClock
{
    public function now(): int
    {
        return time();
    }
}
