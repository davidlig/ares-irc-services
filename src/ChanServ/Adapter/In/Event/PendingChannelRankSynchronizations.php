<?php

declare(strict_types=1);

namespace App\ChanServ\Adapter\In\Event;

use function array_keys;
use function strtolower;

final class PendingChannelRankSynchronizations
{
    /**
     * Set of ASCII-lowered network channel names; each name is handed back to the network as is.
     *
     * ASCII lowering never merges two channels the network treats as distinct; on an rfc1459
     * network two equivalent spellings only cost a harmless second synchronization pass.
     *
     * @var array<string, true>
     */
    private array $pending = [];

    /** @var array<string, true> */
    private array $pendingAtMessageStart = [];

    public function schedule(string $channelName): void
    {
        $this->pending[strtolower($channelName)] = true;
    }

    public function beginMessage(): void
    {
        $this->pendingAtMessageStart = $this->pending;
    }

    /** @return list<string> */
    public function releaseMessageStartSnapshot(): array
    {
        $channels = array_keys($this->pendingAtMessageStart);
        foreach ($channels as $channelName) {
            unset($this->pending[$channelName]);
        }
        $this->pendingAtMessageStart = [];

        return $channels;
    }
}
