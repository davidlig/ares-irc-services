<?php

declare(strict_types=1);

namespace App\ChanServ\Application\Port\In;

interface UnsuspendedChannelRestoration
{
    public function restore(string $channelNameLower): ?string;
}
