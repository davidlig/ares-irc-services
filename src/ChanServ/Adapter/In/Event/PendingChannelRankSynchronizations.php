<?php

declare(strict_types=1);

namespace App\ChanServ\Adapter\In\Event;

use function array_keys;
use function array_values;
use function strtolower;

final class PendingChannelRankSynchronizations
{
    /**
     * Keyed by the ASCII-lowered network channel name, which is handed back to the network.
     *
     * ASCII lowering never merges two channels the network treats as distinct; on an rfc1459
     * network two equivalent spellings only cost a harmless second synchronization pass.
     *
     * @var array<string, string>
     */
    private array $pending = [];

    /** @var array<string, string> */
    private array $pendingAtMessageStart = [];

    public function schedule(string $channelName): void
    {
        $key = strtolower($channelName);
        $this->pending[$key] = $key;
    }

    public function beginMessage(): void
    {
        $this->pendingAtMessageStart = $this->pending;
    }

    /** @return list<string> */
    public function releaseMessageStartSnapshot(): array
    {
        $channels = array_values($this->pendingAtMessageStart);
        foreach (array_keys($this->pendingAtMessageStart) as $key) {
            unset($this->pending[$key]);
        }
        $this->pendingAtMessageStart = [];

        return $channels;
    }
}
