<?php

declare(strict_types=1);

namespace App\ChanServ\Application\Port\Out;

interface ChannelEntryMessageDelivery
{
    public function deliver(string $channelName, string $targetUid, string $entryMessage): void;
}
