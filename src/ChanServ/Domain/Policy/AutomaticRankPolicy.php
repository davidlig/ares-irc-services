<?php

declare(strict_types=1);

namespace App\ChanServ\Domain\Policy;

use App\ChanServ\Domain\ValueObject\AccessLevel;
use App\ChanServ\Domain\ValueObject\ChannelLevel;
use App\ChanServ\Domain\ValueObject\ChannelLevelSet;
use App\ChanServ\Domain\ValueObject\ChannelRank;

use function in_array;

final readonly class AutomaticRankPolicy
{
    /**
     * @param list<ChannelRank> $supportedRanks
     */
    public function desiredRank(
        bool $identified,
        bool $registered,
        bool $founder,
        AccessLevel $access,
        ChannelLevelSet $levels,
        array $supportedRanks,
    ): ?ChannelRank {
        if (!$identified || !$registered) {
            return null;
        }

        if ($founder) {
            foreach (ChannelRank::highestFirst() as $rank) {
                if (in_array($rank, $supportedRanks, true)) {
                    return $rank;
                }
            }

            return null;
        }

        $thresholds = [
            ChannelRank::Administrator->value => ChannelLevel::AutoAdmin,
            ChannelRank::Operator->value => ChannelLevel::AutoOperator,
            ChannelRank::HalfOperator->value => ChannelLevel::AutoHalfOperator,
            ChannelRank::Voice->value => ChannelLevel::AutoVoice,
        ];

        foreach ([ChannelRank::Administrator, ChannelRank::Operator, ChannelRank::HalfOperator, ChannelRank::Voice] as $rank) {
            if (in_array($rank, $supportedRanks, true) && $access->meets($levels->valueFor($thresholds[$rank->value]))) {
                return $rank;
            }
        }

        return null;
    }
}
