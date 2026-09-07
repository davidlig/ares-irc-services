<?php

declare(strict_types=1);

namespace App\ChanServ\Adapter\In\Event;

final class PendingChannelRankSynchronizations
{
    /** @var array<string, true> */
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
            unset($this->pending[$channelName], $this->pendingAtMessageStart[$channelName]);
        }

        return $channels;
    }
}
