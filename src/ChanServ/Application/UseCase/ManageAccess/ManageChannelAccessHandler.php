<?php

declare(strict_types=1);

namespace App\ChanServ\Application\UseCase\ManageAccess;

use App\ChanServ\Application\Port\Out\ChannelAccessRepositoryInterface;
use App\ChanServ\Application\Port\Out\ChanServEventPublisher;
use App\ChanServ\Application\Port\Out\ChanUserAccountPort;
use App\ChanServ\Application\Port\Out\RegisteredChannelRepositoryInterface;
use App\ChanServ\Application\PublishedEvent\ChannelAccessChangedEvent;
use App\ChanServ\Application\Service\ChanServAccessHelper;
use App\ChanServ\Domain\Entity\ChannelAccess;
use App\ChanServ\Domain\Entity\ChannelLevel;
use App\ChanServ\Domain\Entity\RegisteredChannel;
use App\ChanServ\Domain\Exception\ChannelNotRegisteredException;

use function array_values;
use function strtolower;

final readonly class ManageChannelAccessHandler implements ManageChannelAccessHandlerInterface
{
    public function __construct(
        private RegisteredChannelRepositoryInterface $channels,
        private ChannelAccessRepositoryInterface $access,
        private ChanUserAccountPort $accounts,
        private ChanServAccessHelper $accessPolicy,
        private ChanServEventPublisher $events,
    ) {}

    public function handle(ManageChannelAccess $command): ManageChannelAccessResult
    {
        $channel = $this->channels->findByChannelName(strtolower($command->channelName));
        if (null === $channel) {
            throw ChannelNotRegisteredException::forChannel($command->channelName);
        }
        if (null === $command->actorNickId) {
            return new ManageChannelAccessResult(ManageChannelAccessOutcome::ActorNotAuthenticated);
        }

        return match ($command->action) {
            ManageChannelAccessAction::Unknown => new ManageChannelAccessResult(ManageChannelAccessOutcome::UnknownAction),
            ManageChannelAccessAction::List => $this->list($channel, $command, $command->actorNickId),
            ManageChannelAccessAction::Add => $this->add($channel, $command, $command->actorNickId),
            ManageChannelAccessAction::Delete => $this->delete($channel, $command, $command->actorNickId),
        };
    }

    private function list(RegisteredChannel $channel, ManageChannelAccess $command, int $actorNickId): ManageChannelAccessResult
    {
        if (!$command->founderEquivalent) {
            $this->accessPolicy->requireLevel($channel, $actorNickId, ChannelLevel::KEY_ACCESSLIST, $command->channelName, 'ACCESS LIST');
        }

        $entries = [];
        $accessEntries = $this->access->listByChannel($channel->getId());
        $nicknameIds = [];
        foreach ($accessEntries as $entry) {
            $nicknameIds[$entry->getNickId()] = $entry->getNickId();
        }
        $nicknames = [] === $nicknameIds ? [] : $this->accounts->findNicknamesByIds(array_values($nicknameIds));
        foreach ($accessEntries as $entry) {
            $nickId = $entry->getNickId();
            $entries[] = new ChannelAccessEntryView($nicknames[$nickId] ?? (string) $nickId, $entry->getLevel());
        }

        return new ManageChannelAccessResult([] === $entries ? ManageChannelAccessOutcome::ListEmpty : ManageChannelAccessOutcome::Listed, $entries);
    }

    private function add(RegisteredChannel $channel, ManageChannelAccess $command, int $actorNickId): ManageChannelAccessResult
    {
        $actorLevel = $command->founderEquivalent
            ? null
            : $this->accessPolicy->requireLevel($channel, $actorNickId, ChannelLevel::KEY_ACCESSCHANGE, $command->channelName, 'ACCESS ADD');

        if (null === $command->targetNickname || '' === $command->targetNickname || null === $command->level) {
            return new ManageChannelAccessResult(ManageChannelAccessOutcome::InvalidRequest);
        }
        if ($command->level < ChannelAccess::LEVEL_MIN || $command->level > ChannelAccess::LEVEL_MAX) {
            return new ManageChannelAccessResult(ManageChannelAccessOutcome::InvalidLevel);
        }

        $nickname = $command->targetNickname;
        $target = $this->accounts->findAccountByNick($nickname);
        if (null === $target) {
            return new ManageChannelAccessResult(ManageChannelAccessOutcome::TargetNotRegistered, targetNickname: $nickname);
        }
        $level = $command->level;
        $existing = $this->access->findByChannelAndNick($channel->getId(), $target->id);
        if (null !== $actorLevel) {
            if ($level >= $actorLevel) {
                return new ManageChannelAccessResult(ManageChannelAccessOutcome::CannotManageLevel, targetNickname: $nickname, level: $level);
            }
        }
        if ($channel->isFounder($target->id)) {
            return new ManageChannelAccessResult(ManageChannelAccessOutcome::FounderNotAllowed, targetNickname: $nickname);
        }
        if (null !== $actorLevel && null !== $existing && !$this->accessPolicy->canManageLevel($channel, $actorNickId, $existing->getLevel(), $actorLevel)) {
            return new ManageChannelAccessResult(ManageChannelAccessOutcome::CannotManageLevel, targetNickname: $nickname, level: $level);
        }
        if (null === $existing && $this->access->countByChannel($channel->getId()) >= ChannelAccess::MAX_ENTRIES_PER_CHANNEL) {
            return new ManageChannelAccessResult(ManageChannelAccessOutcome::LimitReached, targetNickname: $nickname, level: $level);
        }

        if (null === $existing) {
            $existing = new ChannelAccess($channel->getId(), $target->id, $level);
        } else {
            $existing->updateLevel($level);
        }
        $this->access->save($existing);
        $this->events->publish(new ChannelAccessChangedEvent(
            $channel->getId(),
            $command->channelName,
            'ADD',
            $target->id,
            $nickname,
            $level,
            $command->performedBy,
            $command->actorNickId,
            $command->performedByIp,
            $command->performedByHost,
            $command->occurredAt,
        ));

        return new ManageChannelAccessResult(ManageChannelAccessOutcome::Added, targetNickname: $nickname, level: $level);
    }

    private function delete(RegisteredChannel $channel, ManageChannelAccess $command, int $actorNickId): ManageChannelAccessResult
    {
        $actorLevel = $command->founderEquivalent
            ? null
            : $this->accessPolicy->requireLevel($channel, $actorNickId, ChannelLevel::KEY_ACCESSCHANGE, $command->channelName, 'ACCESS DEL');

        if (null === $command->targetNickname || '' === $command->targetNickname) {
            return new ManageChannelAccessResult(ManageChannelAccessOutcome::InvalidRequest);
        }

        $nickname = $command->targetNickname;
        $target = $this->accounts->findAccountByNick($nickname);
        if (null === $target) {
            return new ManageChannelAccessResult(ManageChannelAccessOutcome::TargetNotRegistered, targetNickname: $nickname);
        }
        $existing = $this->access->findByChannelAndNick($channel->getId(), $target->id);
        if (null === $existing) {
            return new ManageChannelAccessResult(ManageChannelAccessOutcome::EntryNotFound, targetNickname: $nickname);
        }
        if (null !== $actorLevel && !$this->accessPolicy->canManageLevel($channel, $actorNickId, $existing->getLevel(), $actorLevel)) {
            return new ManageChannelAccessResult(ManageChannelAccessOutcome::CannotManageLevel, targetNickname: $nickname);
        }

        $this->access->remove($existing);
        $this->events->publish(new ChannelAccessChangedEvent(
            $channel->getId(),
            $command->channelName,
            'DEL',
            $target->id,
            $nickname,
            null,
            $command->performedBy,
            $command->actorNickId,
            $command->performedByIp,
            $command->performedByHost,
            $command->occurredAt,
        ));

        return new ManageChannelAccessResult(ManageChannelAccessOutcome::Deleted, targetNickname: $nickname);
    }
}
