<?php

declare(strict_types=1);

namespace App\ChanServ\Application\Port\Out;

use App\ChanServ\Application\Model\MemberRankChange;

interface ChannelRankActions
{
    /** @param list<MemberRankChange> $changes */
    public function apply(string $channelName, array $changes): void;
}
