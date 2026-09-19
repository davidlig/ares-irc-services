<?php

declare(strict_types=1);

namespace App\Irc\Application\Port\Out;

interface AntifloodClock
{
    public function now(): int;
}
