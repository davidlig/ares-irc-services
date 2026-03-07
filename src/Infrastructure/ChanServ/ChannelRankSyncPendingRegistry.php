<?php

declare(strict_types=1);

namespace App\Infrastructure\ChanServ;

/**
 * Holds channel names that need rank sync. Snapshot at message start so we run
 * sync only for channels that were already pending (from a previous message),
 * producing one MODE batch per channel when multiple events touch the same channel.
 */
final class ChannelRankSyncPendingRegistry
{
    /** @var array<string, true> channel name (lowercase) => true */
    private array $pending = [];

    /** @var array<string, true> snapshot at start of current message */
    private array $pendingAtStart = [];

    public function add(string $channelName): void
    {
        $this->pending[strtolower($channelName)] = true;
    }

    /**
     * @param list<object> $channels Entities with getName()
     */
    public function addChannels(iterable $channels): void
    {
        foreach ($channels as $channel) {
            if (method_exists($channel, 'getName')) {
                $this->add($channel->getName());
            }
        }
    }

    /**
     * Call at start of each IRC message (MessageReceivedEvent). Returns current pending
     * so sync runs only for channels that were pending before this message.
     */
    public function snapshotPendingAtStart(): void
    {
        $this->pendingAtStart = $this->pending;
    }

    /**
     * Channels that were pending at message start (to run sync for).
     *
     * @return list<string>
     */
    public function getPendingAtStart(): array
    {
        return array_keys($this->pendingAtStart);
    }

    public function remove(string $channelName): void
    {
        $key = strtolower($channelName);
        unset($this->pending[$key], $this->pendingAtStart[$key]);
    }
}
