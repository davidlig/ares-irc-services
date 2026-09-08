<?php

declare(strict_types=1);

namespace App\ChanServ\Application\Port\Out;

interface ChannelTopicClock
{
    public function now(): float;
}
