<?php

declare(strict_types=1);

namespace App\NickServ\Adapter\Out\Time;

use App\NickServ\Application\Port\Out\Clock;
use DateTimeImmutable;

final readonly class SystemClock implements Clock
{
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable();
    }
}
