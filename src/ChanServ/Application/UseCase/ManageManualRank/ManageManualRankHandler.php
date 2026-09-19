<?php

declare(strict_types=1);

namespace App\ChanServ\Application\UseCase\ManageManualRank;

use App\ChanServ\Application\Model\MemberRankChange;
use App\ChanServ\Application\Port\Out\ChannelRankActions;
use App\ChanServ\Application\Port\Out\ChannelRankNetworkQuery;
use App\ChanServ\Application\Port\Out\RegisteredChannelRepositoryInterface;
use App\ChanServ\Application\Service\ChanServAccessHelper;
use App\ChanServ\Domain\Entity\ChannelAccess;
use App\ChanServ\Domain\Exception\ChannelNotRegisteredException;
use App\ChanServ\Domain\ValueObject\RankChange;
use App\ChanServ\Domain\ValueObject\RankChangeAction;

use function in_array;
use function strcasecmp;
use function strtolower;

final readonly class ManageManualRankHandler implements ManageManualRankHandlerInterface
{
    public function __construct(
        private RegisteredChannelRepositoryInterface $channels,
        private ChannelRankNetworkQuery $network,
        private ChannelRankActions $rankActions,
        private ChanServAccessHelper $access,
    ) {}

    public function handle(ManageManualRank $command): ManageManualRankResult
    {
        $channel = $this->channels->findByChannelName(strtolower($command->channelName));
        if (null === $channel) {
            throw ChannelNotRegisteredException::forChannel($command->channelName);
        }

        if (null === $command->actorAccountId) {
            return ManageManualRankResult::of(ManageManualRankOutcome::NotIdentified, $command->channelName, $command->targetNickname);
        }

        $state = $this->network->findChannel($command->channelName);
        if (null === $state || !in_array($command->operation->rank(), $state->supportedRanks, true)) {
            return ManageManualRankResult::of(ManageManualRankOutcome::RankNotSupported, $command->channelName, $command->targetNickname);
        }

        if (!$command->founderOverride) {
            $this->access->requireLevel(
                $channel,
                $command->actorAccountId,
                $command->operation->requiredLevelKey(),
                $command->channelName,
                $command->operation->value,
            );
        }

        $target = null;
        foreach ($state->members as $member) {
            if (0 === strcasecmp($member->nickname, $command->targetNickname)) {
                $target = $member;
                break;
            }
        }
        if (null === $target) {
            return ManageManualRankResult::of(ManageManualRankOutcome::TargetNotOnChannel, $command->channelName, $command->targetNickname);
        }

        if (RankChangeAction::Grant === $command->operation->action() && null === $target->registeredNickId) {
            return ManageManualRankResult::of(ManageManualRankOutcome::TargetNickNotRegistered, $command->channelName, $command->targetNickname);
        }

        $targetLevel = null === $target->registeredNickId
            ? ChannelAccess::LEVEL_UNREGISTERED
            : $this->access->effectiveAccessLevel($channel, $target->registeredNickId, $target->identified);

        if (RankChangeAction::Grant === $command->operation->action() && !$command->founderOverride && $channel->isSecure()) {
            $required = $this->access->getLevelValue($channel->getId(), $command->operation->automaticLevelKey());
            if ($targetLevel < $required) {
                return ManageManualRankResult::of(ManageManualRankOutcome::SecureLevelRequired, $command->channelName, $command->targetNickname, $required);
            }
        }

        if (RankChangeAction::Revoke === $command->operation->action() && !$command->founderOverride) {
            $actorLevel = $this->access->effectiveAccessLevel($channel, $command->actorAccountId, true);
            $selfTarget = $target->registeredNickId === $command->actorAccountId;
            if (!$selfTarget && $actorLevel <= $targetLevel) {
                return ManageManualRankResult::of(ManageManualRankOutcome::TargetAccessTooHigh, $command->channelName, $command->targetNickname);
            }
        }

        $this->rankActions->apply($command->channelName, [
            new MemberRankChange($target->uid, new RankChange($command->operation->rank(), $command->operation->action())),
        ]);

        return ManageManualRankResult::of(ManageManualRankOutcome::Applied, $command->channelName, $command->targetNickname);
    }
}
