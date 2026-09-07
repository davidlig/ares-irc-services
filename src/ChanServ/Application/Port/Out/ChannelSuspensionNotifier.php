<?php

declare(strict_types=1);

namespace App\ChanServ\Application\Port\Out;

interface ChannelSuspensionNotifier
{
    public function notifyChannelSuspended(string $channelName, ?string $reason): void;
}
