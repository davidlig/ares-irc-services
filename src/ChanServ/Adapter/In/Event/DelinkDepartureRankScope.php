<?php

declare(strict_types=1);

namespace App\ChanServ\Adapter\In\Event;

use App\ChanServ\Application\Model\ChannelRankNetworkState;
use App\ChanServ\Application\Model\ChannelRankPolicy;
use App\ChanServ\Application\Port\Out\ChannelRankNetworkQuery;
use App\ChanServ\Application\Port\Out\ChannelRankPolicyRepository;

use function array_key_exists;

/**
 * Channel rank snapshots shared by every departure of one server delink (SQUIT).
 *
 * The first departure from a channel loads its policy and network state once, while every split
 * member is still on the channel (a departure is published before the member is removed); later
 * departures from that channel reuse the snapshot, so a split costs one rank pass per affected
 * channel instead of one per departing member.
 */
final class DelinkDepartureRankScope
{
    private bool $active = false;

    /**
     * Keyed by the channel name exactly as the network publishes it; null marks an unregistered channel.
     *
     * @var array<string, array{ChannelRankPolicy, ?ChannelRankNetworkState}|null>
     */
    private array $channels = [];

    public function __construct(
        private readonly ChannelRankPolicyRepository $policies,
        private readonly ChannelRankNetworkQuery $network,
    ) {}

    public function begin(): void
    {
        $this->active = true;
        $this->channels = [];
    }

    public function end(): void
    {
        $this->active = false;
        $this->channels = [];
    }

    public function active(): bool
    {
        return $this->active;
    }

    /** @return array{ChannelRankPolicy, ?ChannelRankNetworkState}|null null when the channel is not registered */
    public function channel(string $channelName): ?array
    {
        if (!array_key_exists($channelName, $this->channels)) {
            $policy = $this->policies->findByName($channelName);
            $this->channels[$channelName] = null === $policy
                ? null
                : [$policy, $policy->blocked ? null : $this->network->findChannel($channelName)];
        }

        return $this->channels[$channelName];
    }
}
