<?php

declare(strict_types=1);

namespace App\ChanServ\Application\Port\Out;

interface ChannelBanActions
{
    /** @param list<string> $masks Original matching masks only. */
    public function removeBans(string $channelName, array $masks, ?int $channelTimestamp): void;
}
