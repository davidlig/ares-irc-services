<?php

declare(strict_types=1);

namespace App\ChanServ\Application\Port\Out;

use DateTimeImmutable;

interface BurstChannelActivity
{
    /** @param list<int> $channelIds */
    public function touchByIds(array $channelIds, DateTimeImmutable $at): void;
}
