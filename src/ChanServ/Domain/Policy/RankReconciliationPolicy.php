<?php

declare(strict_types=1);

namespace App\ChanServ\Domain\Policy;

use App\ChanServ\Domain\ValueObject\ChannelRank;
use App\ChanServ\Domain\ValueObject\RankChange;
use App\ChanServ\Domain\ValueObject\RankChangeAction;

use function in_array;

final readonly class RankReconciliationPolicy
{
    /**
     * @param list<ChannelRank> $currentRanks
     *
     * @return list<RankChange>
     */
    public function forJoin(
        ?ChannelRank $joinedRank,
        array $currentRanks,
        ?ChannelRank $desiredRank,
        bool $secure,
    ): array {
        $changes = [];
        if ($secure && null !== $joinedRank && $joinedRank->isHigherThan($desiredRank)) {
            $changes[] = new RankChange($joinedRank, RankChangeAction::Revoke);
        }

        if (null !== $desiredRank && !in_array($desiredRank, $currentRanks, true)) {
            $changes[] = new RankChange($desiredRank, RankChangeAction::Grant);
        }

        return $changes;
    }

    /**
     * Full synchronization deliberately downgrades ranks even when SECURE is disabled.
     *
     * @param list<ChannelRank> $currentRanks
     * @param list<ChannelRank> $supportedRanks
     *
     * @return list<RankChange>
     */
    public function forFullSync(array $currentRanks, ?ChannelRank $desiredRank, array $supportedRanks): array
    {
        $changes = [];
        foreach (ChannelRank::highestFirst() as $rank) {
            if (
                in_array($rank, $supportedRanks, true)
                && in_array($rank, $currentRanks, true)
                && $rank->isHigherThan($desiredRank)
            ) {
                $changes[] = new RankChange($rank, RankChangeAction::Revoke);
            }
        }

        if (
            null !== $desiredRank
            && in_array($desiredRank, $supportedRanks, true)
            && !in_array($desiredRank, $currentRanks, true)
        ) {
            $changes[] = new RankChange($desiredRank, RankChangeAction::Grant);
        }

        return $changes;
    }

    /** @return list<RankChange> */
    public function forLiveGrant(ChannelRank $grantedRank, ?ChannelRank $desiredRank, bool $secure): array
    {
        if (!$secure || !$grantedRank->isHigherThan($desiredRank)) {
            return [];
        }

        return [new RankChange($grantedRank, RankChangeAction::Revoke)];
    }
}
