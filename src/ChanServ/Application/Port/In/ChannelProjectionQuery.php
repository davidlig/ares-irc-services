<?php

declare(strict_types=1);

namespace App\ChanServ\Application\Port\In;

interface ChannelProjectionQuery
{
    /** @return list<ChannelProjection> */
    public function all(): array;

    public function findByName(string $channelName): ?ChannelProjection;
}
