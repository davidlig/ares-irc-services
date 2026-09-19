<?php

declare(strict_types=1);

namespace App\ChanServ\Adapter\Out\Time;

use App\ChanServ\Application\Port\Out\ChannelTopicClock;

use function microtime;

final readonly class SystemChannelTopicClock implements ChannelTopicClock
{
    public function now(): float
    {
        return microtime(true);
    }
}
