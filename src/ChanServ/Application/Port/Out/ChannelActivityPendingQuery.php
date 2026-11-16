<?php

declare(strict_types=1);

namespace App\ChanServ\Application\Port\Out;

/**
 * Tells whether a channel has recorded activity that is not yet persisted, so the stored
 * last-used timestamp of that channel cannot yet be trusted to decide inactivity.
 */
interface ChannelActivityPendingQuery
{
    public function hasPendingActivity(int $channelId): bool;
}
