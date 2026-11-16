<?php

declare(strict_types=1);

namespace App\ChanServ\Adapter\In\Event;

use App\ChanServ\Application\Model\ChannelRankNetworkState;
use App\ChanServ\Application\Model\ChannelRankPolicy;
use App\ChanServ\Application\Port\Out\ChannelRankNetworkQuery;
use App\ChanServ\Application\Port\Out\ChannelRankPolicyRepository;

use function array_key_exists;

/**
 * Channel rank policies shared by every departure of one server delink (SQUIT).
 *
 * The first departure from a channel loads its policy once and later departures from that channel
 * reuse it, so a split costs one policy load per affected channel instead of one per departing
 * member. Network state is resolved for the departing member only (a departure is published before
 * the member is removed), so the cost follows the departures, not the size of the channel.
 */
final class DelinkDepartureRankScope
{
    private bool $active = false;

    /**
     * Keyed by the channel name exactly as the network publishes it; null marks an unregistered channel.
     *
     * @var array<string, ChannelRankPolicy|null>
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

    /**
     * @return array{ChannelRankPolicy, ?ChannelRankNetworkState}|null null when the channel is not registered;
     *                                                                 the state holds only the departing member
     */
    public function departure(string $channelName, string $uid): ?array
    {
        if (!array_key_exists($channelName, $this->channels)) {
            $this->channels[$channelName] = $this->policies->findByName($channelName);
        }
        $policy = $this->channels[$channelName];
        if (null === $policy) {
            return null;
        }

        return [$policy, $policy->blocked ? null : $this->network->findMember($channelName, $uid)];
    }
}
