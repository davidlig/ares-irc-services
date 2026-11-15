<?php

declare(strict_types=1);

namespace App\ChanServ\Application\Port\In;

interface UnsuspendedChannelRestoration
{
    /**
     * @param string $channelNameLower the stored RFC 1459 key of the channel
     *
     * @return string|null the stored channel name, or null when the channel no longer exists
     */
    public function restore(string $channelNameLower): ?string;
}
