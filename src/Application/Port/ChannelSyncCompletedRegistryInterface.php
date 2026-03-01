<?php

declare(strict_types=1);

namespace App\Application\Port;

/**
 * Tracks which channels have completed their sync (ChanServ +r, SECURE strip, MLOCK, topic apply).
 * Used to avoid persisting topic (or other DB changes) from the wire until our sync has run,
 * preventing a user who temporarily has op from overwriting stored topic before we strip their rank.
 */
interface ChannelSyncCompletedRegistryInterface
{
    public function markSyncCompleted(string $channelName): void;

    public function isSyncCompleted(string $channelName): bool;

    /** Unix timestamp when sync was marked completed (for grace-period checks). */
    public function getSyncCompletedAt(string $channelName): ?float;
}
