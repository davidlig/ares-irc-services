<?php

declare(strict_types=1);

namespace App\ChanServ\Application\Model;

use App\ChanServ\Application\Port\Out\NetworkIdentityFold;

/**
 * In-memory state for synchronous events emitted during one EOS reconciliation.
 *
 * Network channel names are compared with the casemapping the IRCd negotiated, as everywhere
 * else a network channel is matched against a registered one.
 */
final class BurstRankEventScope
{
    private bool $active = false;

    private ?ChannelRankPolicy $policy = null;

    private ?string $policyKey = null;

    private ?ChannelRankNetworkState $network = null;

    /** @var array<string, true> */
    private array $activity = [];

    public function __construct(private readonly NetworkIdentityFold $identityFold) {}

    public function begin(): void
    {
        $this->active = true;
    }

    public function end(): void
    {
        $this->active = false;
        $this->policy = null;
        $this->policyKey = null;
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
        $this->policyKey = null === $policy ? null : $this->identityFold->fold($policy->name);
        $this->network = $network;
    }

    /** @return array{ChannelRankPolicy, ?ChannelRankNetworkState}|null */
    public function channel(string $name): ?array
    {
        if (null === $this->policy || $this->policyKey !== $this->identityFold->fold($name)) {
            return null;
        }

        return [$this->policy, $this->network];
    }

    public function markActivity(string $name): void
    {
        $this->activity[$this->identityFold->fold($name)] = true;
    }

    public function needsActivity(string $name): bool
    {
        return isset($this->activity[$this->identityFold->fold($name)]);
    }
}
