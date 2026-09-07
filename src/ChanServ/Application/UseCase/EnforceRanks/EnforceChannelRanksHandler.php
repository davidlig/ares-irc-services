<?php

declare(strict_types=1);

namespace App\ChanServ\Application\UseCase\EnforceRanks;

use App\ChanServ\Application\Model\ChannelMember;
use App\ChanServ\Application\Model\ChannelRankPolicy;
use App\ChanServ\Application\Model\MemberRankChange;
use App\ChanServ\Application\Port\Out\ChannelRankActions;
use App\ChanServ\Application\Port\Out\ChannelRankNetworkQuery;
use App\ChanServ\Application\Port\Out\ChannelRankPolicyRepository;
use App\ChanServ\Domain\Policy\AutomaticRankPolicy;
use App\ChanServ\Domain\Policy\ChannelAccessPolicy;
use App\ChanServ\Domain\Policy\RankReconciliationPolicy;
use App\ChanServ\Domain\ValueObject\ChannelRank;
use App\ChanServ\Domain\ValueObject\RankChange;

use function count;

final readonly class EnforceChannelRanksHandler implements EnforceChannelRanksHandlerInterface
{
    public function __construct(
        private ChannelRankPolicyRepository $channelPolicies,
        private ChannelRankNetworkQuery $network,
        private ChannelRankActions $rankActions,
        private ChannelAccessPolicy $accessPolicy,
        private AutomaticRankPolicy $automaticRankPolicy,
        private RankReconciliationPolicy $reconciliationPolicy,
    ) {}

    public function handle(EnforceChannelRanks $command): RankEnforcementResult
    {
        $channel = $this->channelPolicies->findByName($command->channelName);
        if (null === $channel) {
            return new RankEnforcementResult(RankEnforcementOutcome::ChannelUnavailable);
        }

        return $this->handleKnownPolicy($command, $channel);
    }

    public function handleKnownPolicy(EnforceChannelRanks $command, ChannelRankPolicy $channel): RankEnforcementResult
    {
        if ($channel->blocked) {
            return new RankEnforcementResult(RankEnforcementOutcome::ChannelBlocked);
        }

        $networkChannel = $this->network->findChannel($channel->name);
        if (null === $networkChannel) {
            if (RankEnforcementTrigger::ChannelSynchronized === $command->trigger) {
                $this->channelPolicies->touchLastUsed($channel->id);

                return new RankEnforcementResult(RankEnforcementOutcome::NoChanges, activityTouched: true);
            }

            return new RankEnforcementResult(RankEnforcementOutcome::ChannelUnavailable);
        }

        if (RankEnforcementTrigger::ChannelSynchronized === $command->trigger) {
            $this->channelPolicies->touchLastUsed($channel->id);
        }

        if (RankEnforcementTrigger::MemberLeft === $command->trigger) {
            return $this->recordDeparture(
                $channel,
                $networkChannel->member((string) $command->uid),
                $networkChannel->supportedRanks,
            );
        }

        if (RankEnforcementTrigger::MemberJoined === $command->trigger || RankEnforcementTrigger::LiveRankGranted === $command->trigger) {
            $member = $networkChannel->member((string) $command->uid);
            if (null === $member) {
                return new RankEnforcementResult(RankEnforcementOutcome::MemberUnavailable);
            }

            return $this->enforceSingleMember($command, $channel, $member, $networkChannel->supportedRanks);
        }

        $rankChanges = [];
        foreach ($networkChannel->members as $member) {
            if ($member->service) {
                continue;
            }
            foreach ($this->changesForMember($command, $channel, $member, $networkChannel->supportedRanks) as $change) {
                $rankChanges[] = new MemberRankChange($member->uid, $change);
            }
        }
        if ([] !== $rankChanges) {
            $this->rankActions->apply($channel->name, $rankChanges);
        }

        $activityTouched = RankEnforcementTrigger::ChannelSynchronized === $command->trigger;

        return new RankEnforcementResult(
            [] !== $rankChanges || $activityTouched ? RankEnforcementOutcome::Applied : RankEnforcementOutcome::NoChanges,
            count($rankChanges),
            $activityTouched,
        );
    }

    /** @param list<ChannelRank> $supportedRanks */
    private function enforceSingleMember(
        EnforceChannelRanks $command,
        ChannelRankPolicy $channel,
        ChannelMember $member,
        array $supportedRanks,
    ): RankEnforcementResult {
        $changes = $this->changesForMember($command, $channel, $member, $supportedRanks);
        $desired = $this->desiredRank($channel, $member, $supportedRanks);

        $touched = false;
        if (RankEnforcementTrigger::MemberJoined === $command->trigger && null !== $desired) {
            $this->channelPolicies->touchLastUsed($channel->id);
            $touched = true;
        }
        if ([] !== $changes) {
            $memberChanges = array_map(
                static fn ($change): MemberRankChange => new MemberRankChange($member->uid, $change),
                $changes,
            );
            $this->rankActions->apply($channel->name, $memberChanges);
        }

        return new RankEnforcementResult(
            [] === $changes && !$touched ? RankEnforcementOutcome::NoChanges : RankEnforcementOutcome::Applied,
            count($changes),
            $touched,
        );
    }

    /**
     * @param list<ChannelRank> $supportedRanks
     *
     * @return list<RankChange>
     */
    private function changesForMember(
        EnforceChannelRanks $command,
        ChannelRankPolicy $channel,
        ChannelMember $member,
        array $supportedRanks,
    ): array {
        $desired = $this->desiredRank($channel, $member, $supportedRanks);

        return match ($command->trigger) {
            RankEnforcementTrigger::MemberJoined => $this->reconciliationPolicy->forJoin(
                $command->joinedRank,
                $member->currentRanks,
                $desired,
                $channel->secure,
            ),
            RankEnforcementTrigger::LiveRankGranted => $this->liveGrantChanges($command->grantedRank, $desired, $channel->secure),
            default => $this->reconciliationPolicy->forFullSync($member->currentRanks, $desired, $supportedRanks),
        };
    }

    /** @param list<ChannelRank> $supportedRanks */
    private function recordDeparture(
        ChannelRankPolicy $channel,
        ?ChannelMember $member,
        array $supportedRanks,
    ): RankEnforcementResult {
        if (null === $member) {
            return new RankEnforcementResult(RankEnforcementOutcome::MemberUnavailable);
        }
        $desired = $this->desiredRank($channel, $member, $supportedRanks);
        if (null === $desired) {
            return new RankEnforcementResult(RankEnforcementOutcome::NoChanges);
        }

        $this->channelPolicies->touchLastUsed($channel->id);

        return new RankEnforcementResult(RankEnforcementOutcome::Applied, activityTouched: true);
    }

    /** @param list<ChannelRank> $supportedRanks */
    private function desiredRank(ChannelRankPolicy $channel, ChannelMember $member, array $supportedRanks): ?ChannelRank
    {
        $founder = $channel->isFounder($member->registeredNickId);
        $access = $this->accessPolicy->effectiveLevel(
            $member->identified,
            $founder,
            $channel->storedAccessFor($member->registeredNickId),
        );

        return $this->automaticRankPolicy->desiredRank(
            $member->identified,
            null !== $member->registeredNickId,
            $founder,
            $access,
            $channel->levels,
            $supportedRanks,
        );
    }

    /** @return list<RankChange> */
    private function liveGrantChanges(?ChannelRank $granted, ?ChannelRank $desired, bool $secure): array
    {
        if (null === $granted) {
            return [];
        }

        return $this->reconciliationPolicy->forLiveGrant($granted, $desired, $secure);
    }
}
