<?php

declare(strict_types=1);

namespace App\ChanServ\Application\UseCase\ManageAkick;

use App\ChanServ\Application\Port\Out\AkickActions;
use App\ChanServ\Application\Port\Out\ChannelAccessRepositoryInterface;
use App\ChanServ\Application\Port\Out\ChannelAkickRepositoryInterface;
use App\ChanServ\Application\Port\Out\ChannelEntryNetworkQuery;
use App\ChanServ\Application\Port\Out\ChanServEventPublisher;
use App\ChanServ\Application\Port\Out\ChanUserAccountPort;
use App\ChanServ\Application\Port\Out\RegisteredChannelRepositoryInterface;
use App\ChanServ\Application\PublishedEvent\ChannelAkickChangedEvent;
use App\ChanServ\Application\Service\ChanServAccessHelper;
use App\ChanServ\Application\UseCase\EnforceAkick\AkickUseRecorder;
use App\ChanServ\Application\UseCase\EnforceAkick\EnforceChannelAkickHandlerInterface;
use App\ChanServ\Domain\Entity\ChannelAkick;
use App\ChanServ\Domain\Entity\ChannelLevel;
use App\ChanServ\Domain\Entity\RegisteredChannel;
use App\ChanServ\Domain\Exception\ChannelNotRegisteredException;
use App\ChanServ\Domain\Policy\AkickAdditionPolicy;
use App\ChanServ\Domain\Policy\AkickMatchPolicy;
use App\ChanServ\Domain\Policy\AkickProtectionPolicy;
use App\ChanServ\Domain\ValueObject\AkickAdditionDecision;
use App\ChanServ\Domain\ValueObject\AkickMask;
use App\ChanServ\Domain\ValueObject\AkickRule;
use DateTimeImmutable;
use InvalidArgumentException;

use function array_values;
use function strlen;

use const PHP_INT_MIN;

final readonly class ManageChannelAkickHandler implements ManageChannelAkickHandlerInterface
{
    /** Sort key of an entry that was never applied: it always comes last. */
    private const int NEVER_USED = PHP_INT_MIN;

    public function __construct(
        private RegisteredChannelRepositoryInterface $channels,
        private ChannelAkickRepositoryInterface $akicks,
        private ChanUserAccountPort $accounts,
        private ChannelAccessRepositoryInterface $access,
        private ChanServAccessHelper $accessPolicy,
        private AkickAdditionPolicy $additionPolicy,
        private AkickMatchPolicy $matchPolicy,
        private AkickProtectionPolicy $protectionPolicy,
        private ChannelEntryNetworkQuery $network,
        private AkickActions $actions,
        private ChanServEventPublisher $events,
        private EnforceChannelAkickHandlerInterface $enforcer,
        private AkickUseRecorder $uses,
    ) {}

    public function handle(ManageChannelAkick $command): ManageChannelAkickResult
    {
        $channel = $this->channels->findByChannelName(strtolower($command->channelName));
        if (null === $channel) {
            throw ChannelNotRegisteredException::forChannel($command->channelName);
        }
        if (null === $command->actorNickId) {
            return new ManageChannelAkickResult(ManageChannelAkickOutcome::ActorNotAuthenticated);
        }
        if (null === $command->performedBy) {
            return new ManageChannelAkickResult(ManageChannelAkickOutcome::MissingSender);
        }

        $performedBy = $command->performedBy;

        return match ($command->action) {
            ManageChannelAkickAction::List => $this->list($channel, $command, $command->actorNickId),
            ManageChannelAkickAction::Add => $this->add($channel, $command, $command->actorNickId, $performedBy),
            ManageChannelAkickAction::Delete => $this->delete($channel, $command, $command->actorNickId, $performedBy),
            ManageChannelAkickAction::Set => $this->set($channel, $command, $command->actorNickId),
            ManageChannelAkickAction::Clear => $this->clear($channel, $command, $command->actorNickId),
            ManageChannelAkickAction::Count => $this->count($channel, $command, $command->actorNickId),
            ManageChannelAkickAction::Check => $this->check($channel, $command, $command->actorNickId),
            ManageChannelAkickAction::Enforce => $this->enforce($channel, $command, $command->actorNickId),
            ManageChannelAkickAction::View => $this->view($channel, $command, $command->actorNickId),
            ManageChannelAkickAction::LastUse => $this->lastUse($channel, $command, $command->actorNickId),
            ManageChannelAkickAction::Unknown => new ManageChannelAkickResult(ManageChannelAkickOutcome::UnknownAction),
        };
    }

    private function list(RegisteredChannel $channel, ManageChannelAkick $command, int $actorNickId): ManageChannelAkickResult
    {
        $this->authorize($channel, $command, $actorNickId, 'AKICK LIST', ChannelLevel::KEY_AKICK);

        $stored = array_values($this->akicks->listByChannel($channel->getId()));
        if ([] === $stored) {
            return new ManageChannelAkickResult(ManageChannelAkickOutcome::ListEmpty);
        }

        $entries = $this->entriesOf($stored);

        if ($this->network->synchronizationComplete()) {
            $this->enforceRules($channel->getName(), array_values($this->akicks->listByChannel($channel->getId())), $command->now);
        }

        return new ManageChannelAkickResult(ManageChannelAkickOutcome::Listed, $entries);
    }

    private function view(RegisteredChannel $channel, ManageChannelAkick $command, int $actorNickId): ManageChannelAkickResult
    {
        $this->authorize($channel, $command, $actorNickId, 'AKICK VIEW', ChannelLevel::KEY_AKICK);

        $entries = $this->entriesOf(array_values($this->akicks->listByChannel($channel->getId())));
        if ([] === $entries) {
            return new ManageChannelAkickResult(ManageChannelAkickOutcome::ViewEmpty);
        }

        return new ManageChannelAkickResult(ManageChannelAkickOutcome::Viewed, $entries);
    }

    private function lastUse(RegisteredChannel $channel, ManageChannelAkick $command, int $actorNickId): ManageChannelAkickResult
    {
        $this->authorize($channel, $command, $actorNickId, 'AKICK LASTUSE', ChannelLevel::KEY_AKICK);

        $entries = $this->entriesOf(array_values($this->akicks->listByChannel($channel->getId())));
        if ([] === $entries) {
            return new ManageChannelAkickResult(ManageChannelAkickOutcome::LastUseEmpty);
        }

        usort($entries, self::compareByLastUse(...));

        return new ManageChannelAkickResult(ManageChannelAkickOutcome::LastUsed, $entries);
    }

    private function add(RegisteredChannel $channel, ManageChannelAkick $command, int $actorNickId, string $performedBy): ManageChannelAkickResult
    {
        $this->authorize($channel, $command, $actorNickId, 'AKICK ADD', ChannelLevel::KEY_AKICK);

        if (!$command->validRequest || null === $command->item || '' === $command->item) {
            return new ManageChannelAkickResult(ManageChannelAkickOutcome::InvalidRequest);
        }

        try {
            $mask = new AkickMask($command->item);
        } catch (InvalidArgumentException) {
            return new ManageChannelAkickResult(ManageChannelAkickOutcome::InvalidMask);
        }
        if (!$mask->isSafe()) {
            return new ManageChannelAkickResult(ManageChannelAkickOutcome::DangerousMask, mask: $mask->value);
        }

        $protected = $this->protectionPolicy->firstProtectedNickname($mask, $this->protectedNicknames($channel));
        if (null !== $protected) {
            return new ManageChannelAkickResult(ManageChannelAkickOutcome::ProtectedUser, protectedNickname: $protected);
        }

        $entryCount = $this->akicks->countByChannel($channel->getId());
        $existing = $this->akicks->findByChannelAndMask($channel->getId(), $mask->value);
        $decision = $this->additionPolicy->decide(
            $entryCount,
            null === $existing ? null : new AkickRule($mask, $existing->getReason(), $existing->getExpiresAt()),
            $command->now,
        );
        if (AkickAdditionDecision::DuplicateActive === $decision) {
            return new ManageChannelAkickResult(ManageChannelAkickOutcome::DuplicateActive, mask: $mask->value);
        }
        if (AkickAdditionDecision::LimitReached === $decision) {
            return new ManageChannelAkickResult(ManageChannelAkickOutcome::LimitReached, mask: $mask->value);
        }
        if (AkickAdditionDecision::ReplaceExpired === $decision && null !== $existing) {
            $this->akicks->remove($existing);
        }

        $akick = ChannelAkick::create($command->now, $channel->getId(), $actorNickId, $mask->value, $command->reason, $command->expiresAt);
        $this->akicks->save($akick);

        if ($this->network->synchronizationComplete()) {
            $this->enforceRules($channel->getName(), [$akick], $command->now);
        }

        $this->events->publish(new ChannelAkickChangedEvent(
            channelId: $channel->getId(),
            channelName: $command->channelName,
            action: 'ADD',
            mask: $mask->value,
            reason: $command->reason,
            performedBy: $performedBy,
            performedByNickId: $actorNickId,
            performedByIp: $command->performedByIp,
            performedByHost: $command->performedByHost,
            occurredAt: $command->now,
        ));

        return new ManageChannelAkickResult(ManageChannelAkickOutcome::Added, mask: $mask->value, reason: $command->reason);
    }

    private function delete(RegisteredChannel $channel, ManageChannelAkick $command, int $actorNickId, string $performedBy): ManageChannelAkickResult
    {
        $this->authorize($channel, $command, $actorNickId, 'AKICK DEL', ChannelLevel::KEY_AKICK);

        if (!$command->validRequest || null === $command->item || '' === $command->item) {
            return new ManageChannelAkickResult(ManageChannelAkickOutcome::InvalidRequest);
        }

        $akick = $this->findByItem($channel->getId(), $command->item);
        if (null === $akick) {
            return new ManageChannelAkickResult(ManageChannelAkickOutcome::EntryNotFound, mask: $command->item);
        }

        $mask = $akick->getMask();
        $this->akicks->remove($akick);
        $this->events->publish(new ChannelAkickChangedEvent(
            channelId: $channel->getId(),
            channelName: $command->channelName,
            action: 'DEL',
            mask: $mask,
            reason: null,
            performedBy: $performedBy,
            performedByNickId: $actorNickId,
            performedByIp: $command->performedByIp,
            performedByHost: $command->performedByHost,
            occurredAt: $command->now,
        ));

        return new ManageChannelAkickResult(ManageChannelAkickOutcome::Deleted, mask: $mask);
    }

    private function set(RegisteredChannel $channel, ManageChannelAkick $command, int $actorNickId): ManageChannelAkickResult
    {
        $this->authorize($channel, $command, $actorNickId, 'AKICK SET', ChannelLevel::KEY_AKICK);

        if (!$command->validRequest || null === $command->item || '' === $command->item) {
            return new ManageChannelAkickResult(ManageChannelAkickOutcome::InvalidRequest);
        }

        if (null !== $command->reason && ChannelAkick::MAX_REASON_LENGTH < strlen($command->reason)) {
            return new ManageChannelAkickResult(ManageChannelAkickOutcome::InvalidReason, mask: $command->item);
        }

        $akick = $this->findByItem($channel->getId(), $command->item);
        if (null === $akick) {
            return new ManageChannelAkickResult(ManageChannelAkickOutcome::EntryNotFound, mask: $command->item);
        }

        $akick->updateReason($command->reason);
        $this->akicks->save($akick);

        return new ManageChannelAkickResult(
            ManageChannelAkickOutcome::ReasonUpdated,
            mask: $akick->getMask(),
            reason: $command->reason,
        );
    }

    private function clear(RegisteredChannel $channel, ManageChannelAkick $command, int $actorNickId): ManageChannelAkickResult
    {
        $this->authorize(
            $channel,
            $command,
            $actorNickId,
            'AKICK CLEAR',
            ChannelLevel::KEY_AKICK,
            ChannelLevel::KEY_AKICKCHANGE,
        );

        $removed = $this->akicks->deleteByChannelId($channel->getId());

        return new ManageChannelAkickResult(ManageChannelAkickOutcome::Cleared, count: $removed);
    }

    private function count(RegisteredChannel $channel, ManageChannelAkick $command, int $actorNickId): ManageChannelAkickResult
    {
        $this->authorize($channel, $command, $actorNickId, 'AKICK COUNT', ChannelLevel::KEY_AKICK);

        return new ManageChannelAkickResult(
            ManageChannelAkickOutcome::Counted,
            count: $this->akicks->countByChannel($channel->getId()),
        );
    }

    private function check(RegisteredChannel $channel, ManageChannelAkick $command, int $actorNickId): ManageChannelAkickResult
    {
        $this->authorize($channel, $command, $actorNickId, 'AKICK CHECK', ChannelLevel::KEY_AKICK);

        if (!$command->validRequest || null === $command->nickname || '' === $command->nickname) {
            return new ManageChannelAkickResult(ManageChannelAkickOutcome::InvalidRequest);
        }

        if (null === $command->userMask) {
            return new ManageChannelAkickResult(
                ManageChannelAkickOutcome::NicknameNotConnected,
                nickname: $command->nickname,
            );
        }

        $entries = [];
        foreach ($this->akicks->listByChannel($channel->getId()) as $akick) {
            $rule = new AkickRule(
                new AkickMask($akick->getMask()),
                $akick->getReason(),
                $akick->getExpiresAt(),
            );
            if (null === $this->matchPolicy->firstMatch([$rule], $command->userMask, $command->now, $command->targetIsOperator)) {
                continue;
            }

            $creatorId = $akick->getCreatorNickId();
            $entries[] = new ChannelAkickEntryView(
                $akick->getMask(),
                $akick->getReason(),
                null === $creatorId ? null : $this->accounts->findNicknameById($creatorId),
                $akick->getExpiresAt(),
                $akick->getCreatedAt(),
                $akick->getUses(),
                $akick->getLastUsedAt(),
            );
        }

        return new ManageChannelAkickResult(
            ManageChannelAkickOutcome::Checked,
            $entries,
            nickname: $command->nickname,
        );
    }

    private function enforce(RegisteredChannel $channel, ManageChannelAkick $command, int $actorNickId): ManageChannelAkickResult
    {
        $this->authorize($channel, $command, $actorNickId, 'AKICK ENFORCE', ChannelLevel::KEY_AKICK);

        $networkChannel = $this->network->findChannel($channel->getName());
        if (null === $networkChannel) {
            return new ManageChannelAkickResult(ManageChannelAkickOutcome::Enforced, count: 0);
        }

        $expelled = $this->enforcer->handleKnownChannel($channel, $networkChannel->members, $command->now);

        return new ManageChannelAkickResult(ManageChannelAkickOutcome::Enforced, count: $expelled);
    }

    private function authorize(RegisteredChannel $channel, ManageChannelAkick $command, int $actorNickId, string $operation, string ...$levelKeys): void
    {
        if ($command->founderEquivalent) {
            return;
        }
        foreach ($levelKeys as $levelKey) {
            $this->accessPolicy->requireLevel($channel, $actorNickId, $levelKey, $command->channelName, $operation);
        }
    }

    /** @return list<string> */
    private function protectedNicknames(RegisteredChannel $channel): array
    {
        $nicknames = [];
        $founder = $this->accounts->findNicknameById($channel->getFounderNickId());
        if (null !== $founder) {
            $nicknames[] = $founder;
        }
        $successorId = $channel->getSuccessorNickId();
        if (null !== $successorId) {
            $successor = $this->accounts->findNicknameById($successorId);
            if (null !== $successor) {
                $nicknames[] = $successor;
            }
        }
        foreach ($this->access->listByChannel($channel->getId()) as $entry) {
            $nickname = $this->accounts->findNicknameById($entry->getNickId());
            if (null !== $nickname) {
                $nicknames[] = $nickname;
            }
        }

        return $nicknames;
    }

    private function findByItem(int $channelId, string $item): ?ChannelAkick
    {
        if (!ctype_digit($item)) {
            return $this->akicks->findByChannelAndMask($channelId, $item);
        }
        $number = (int) $item;
        if (1 > $number) {
            return null;
        }

        return $this->akicks->listByChannel($channelId)[$number - 1] ?? null;
    }

    /**
     * Builds the presentation view of the given entries.
     *
     * @param list<ChannelAkick> $akicks
     *
     * @return list<ChannelAkickEntryView>
     */
    private function entriesOf(array $akicks): array
    {
        $entries = [];
        foreach ($akicks as $akick) {
            $creatorId = $akick->getCreatorNickId();
            $entries[] = new ChannelAkickEntryView(
                $akick->getMask(),
                $akick->getReason(),
                null === $creatorId ? null : $this->accounts->findNicknameById($creatorId),
                $akick->getExpiresAt(),
                $akick->getCreatedAt(),
                $akick->getUses(),
                $akick->getLastUsedAt(),
            );
        }

        return $entries;
    }

    /** Most recent use first; the entries that were never used come last. */
    private static function compareByLastUse(ChannelAkickEntryView $left, ChannelAkickEntryView $right): int
    {
        $leftUse = $left->lastUsedAt?->getTimestamp() ?? self::NEVER_USED;
        $rightUse = $right->lastUsedAt?->getTimestamp() ?? self::NEVER_USED;

        return $rightUse <=> $leftUse;
    }

    /** @param list<ChannelAkick> $akicks */
    private function enforceRules(string $channelName, array $akicks, DateTimeImmutable $now): void
    {
        $channel = $this->network->findChannel($channelName);
        if (null === $channel || [] === $akicks) {
            return;
        }

        $rules = [];
        foreach ($akicks as $akick) {
            $rules[] = new AkickRule(
                new AkickMask($akick->getMask()),
                $akick->getReason(),
                $akick->getExpiresAt(),
            );
        }

        foreach ($channel->members as $member) {
            $matching = $this->matchPolicy->firstMatch($rules, $member->userMask, $now, $member->operator);
            if (null === $matching) {
                continue;
            }

            $this->actions->banAndKick(
                $channel->name,
                $member->uid,
                $matching->mask->value,
                $matching->enforcementReason(),
            );
            $this->uses->record($akicks, $rules, $matching, $now);
        }
    }
}
