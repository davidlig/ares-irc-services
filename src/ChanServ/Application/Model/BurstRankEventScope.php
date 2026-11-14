<?php

declare(strict_types=1);

namespace App\ChanServ\Application\Model;

use App\Shared\Domain\IrcIdentityFold;

/** In-memory state for synchronous events emitted during one EOS reconciliation. */
final class BurstRankEventScope
{
    private bool $active = false;

    private ?ChannelRankPolicy $policy = null;

    private ?ChannelRankNetworkState $network = null;

    /** @var array<string, true> */
    private array $activity = [];

    public function begin(): void
    {
        $this->active = true;
    }

    public function end(): void
    {
        $this->active = false;
        $this->policy = null;
        $this->network = null;
        $this->activity = [];
    }

    public function active(): bool
    {
        return $this->active;
    }

    public function setChannel(?ChannelRankPolicy $policy, ?ChannelRankNetworkState $network): void
    {
        $this->policy = $policy;
        $this->network = $network;
    }

    /** @return array{ChannelRankPolicy, ?ChannelRankNetworkState}|null */
    public function channel(string $name): ?array
    {
        if (null === $this->policy || !IrcIdentityFold::equals($this->policy->name, $name)) {
            return null;
        }

        return [$this->policy, $this->network];
    }

    public function markActivity(string $name): void
    {
        $this->activity[IrcIdentityFold::fold($name)] = true;
    }

    public function needsActivity(string $name): bool
    {
        return isset($this->activity[IrcIdentityFold::fold($name)]);
    }
}
