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

final readonly class ManageChannelAkickHandler implements ManageChannelAkickHandlerInterface
{
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
            ManageChannelAkickAction::Unknown => new ManageChannelAkickResult(ManageChannelAkickOutcome::UnknownAction),
        };
    }

    private function list(RegisteredChannel $channel, ManageChannelAkick $command, int $actorNickId): ManageChannelAkickResult
    {
        $this->authorize($channel, $command, $actorNickId, 'AKICK LIST');

        $stored = $this->akicks->listByChannel($channel->getId());
        if ([] === $stored) {
            return new ManageChannelAkickResult(ManageChannelAkickOutcome::ListEmpty);
        }

        $entries = [];
        foreach ($stored as $akick) {
            $creatorId = $akick->getCreatorNickId();
            $entries[] = new ChannelAkickEntryView(
                $akick->getMask(),
                $akick->getReason(),
                null === $creatorId ? null : $this->accounts->findNicknameById($creatorId),
                $akick->getExpiresAt(),
            );
        }

        if ($this->network->synchronizationComplete()) {
            $this->enforceRules($channel->getName(), array_values($this->akicks->listByChannel($channel->getId())), $command->now);
        }

        return new ManageChannelAkickResult(ManageChannelAkickOutcome::Listed, $entries);
    }

    private function add(RegisteredChannel $channel, ManageChannelAkick $command, int $actorNickId, string $performedBy): ManageChannelAkickResult
    {
        $this->authorize($channel, $command, $actorNickId, 'AKICK ADD');

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
        $this->authorize($channel, $command, $actorNickId, 'AKICK DEL');

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

    private function authorize(RegisteredChannel $channel, ManageChannelAkick $command, int $actorNickId, string $operation): void
    {
        if (!$command->founderEquivalent) {
            $this->accessPolicy->requireLevel($channel, $actorNickId, ChannelLevel::KEY_AKICK, $command->channelName, $operation);
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
        }
    }
}
