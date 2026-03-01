<?php

declare(strict_types=1);

namespace App\Infrastructure\IRC\Network;

use App\Application\Port\ChannelSyncCompletedRegistryInterface;

/**
 * In-memory registry of channel names that have completed sync
 * (all ChannelSyncedEvent subscribers have run: +r, SECURE strip, MLOCK, topic apply).
 */
final class ChannelSyncCompletedRegistry implements ChannelSyncCompletedRegistryInterface
{
    /** @var array<string, float> lowercase channel name => Unix timestamp */
    private array $completedAt = [];

    public function markSyncCompleted(string $channelName): void
    {
        $this->completedAt[strtolower($channelName)] = microtime(true);
    }

    public function isSyncCompleted(string $channelName): bool
    {
        return isset($this->completedAt[strtolower($channelName)]);
    }

    public function getSyncCompletedAt(string $channelName): ?float
    {
        $key = strtolower($channelName);

        return $this->completedAt[$key] ?? null;
    }
}
