<?php

declare(strict_types=1);

namespace App\ChanServ\Adapter\In\Event;

use App\Shared\Domain\IrcIdentityFold;

use function array_keys;
use function array_values;
use function strtolower;

final class PendingChannelRankSynchronizations
{
    /**
     * Keyed by the service identity fold; the value keeps an ASCII-lowered network name.
     *
     * @var array<string, string>
     */
    private array $pending = [];

    /** @var array<string, string> */
    private array $pendingAtMessageStart = [];

    public function schedule(string $channelName): void
    {
        $this->pending[IrcIdentityFold::fold($channelName)] ??= strtolower($channelName);
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
