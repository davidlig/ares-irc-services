<?php

declare(strict_types=1);

namespace App\ChanServ\Application\UseCase\ManageLevels;

use App\ChanServ\Application\Port\Out\ChannelLevelRepositoryInterface;
use App\ChanServ\Application\Port\Out\RegisteredChannelRepositoryInterface;
use App\ChanServ\Domain\Entity\ChannelLevel;

use function in_array;

final readonly class ManageChannelLevelsHandler implements ManageChannelLevelsHandlerInterface
{
    public function __construct(
        private RegisteredChannelRepositoryInterface $channels,
        private ChannelLevelRepositoryInterface $levels,
    ) {}

    public function handle(ManageChannelLevels $command): ManageChannelLevelsResult
    {
        $channel = $this->channels->findByChannelName(strtolower($command->channelName));
        if (null === $channel) {
            return new ManageChannelLevelsResult(ManageChannelLevelsOutcome::ChannelNotRegistered);
        }
        if (null === $command->actorNickId) {
            return new ManageChannelLevelsResult(ManageChannelLevelsOutcome::ActorNotAuthenticated);
        }
        if (!$command->founderEquivalent && !$channel->isFounder($command->actorNickId)) {
            return new ManageChannelLevelsResult(ManageChannelLevelsOutcome::AccessDenied);
        }

        return match ($command->action) {
            ManageChannelLevelsAction::Unknown => new ManageChannelLevelsResult(ManageChannelLevelsOutcome::UnknownAction),
            ManageChannelLevelsAction::List => $this->list($channel->getId(), $command->availableKeys),
            ManageChannelLevelsAction::Set => $this->set($channel->getId(), $command),
            ManageChannelLevelsAction::Reset => $this->reset($channel->getId()),
        };
    }

    /** @param list<string> $availableKeys */
    private function list(int $channelId, array $availableKeys): ManageChannelLevelsResult
    {
        $stored = [];
        foreach ($this->levels->listByChannel($channelId) as $level) {
            $stored[$level->getLevelKey()] = $level->getValue();
        }

        $values = [];
        foreach ($availableKeys as $key) {
            $values[$key] = $stored[$key] ?? ChannelLevel::getDefault($key);
        }

        return new ManageChannelLevelsResult(ManageChannelLevelsOutcome::Listed, $values);
    }

    private function set(int $channelId, ManageChannelLevels $command): ManageChannelLevelsResult
    {
        if (null === $command->levelKey || '' === $command->levelKey || null === $command->value) {
            return new ManageChannelLevelsResult(ManageChannelLevelsOutcome::InvalidRequest);
        }

        $key = $command->levelKey;
        if (!in_array($key, $command->availableKeys, true)) {
            return new ManageChannelLevelsResult(ManageChannelLevelsOutcome::UnknownLevel, levelKey: $key);
        }

        $value = $command->value;
        if ($value < ChannelLevel::LEVEL_MIN || $value > ChannelLevel::LEVEL_MAX) {
            return new ManageChannelLevelsResult(ManageChannelLevelsOutcome::ValueOutOfRange, levelKey: $key, value: $value);
        }

        $level = $this->levels->findByChannelAndKey($channelId, $key);
        if (null === $level) {
            $level = new ChannelLevel($channelId, $key, $value);
        } else {
            $level->updateLevelValue($value);
        }
        $this->levels->save($level);

        return new ManageChannelLevelsResult(ManageChannelLevelsOutcome::LevelSet, levelKey: $key, value: $value);
    }

    private function reset(int $channelId): ManageChannelLevelsResult
    {
        $this->levels->removeAllForChannel($channelId);

        return new ManageChannelLevelsResult(ManageChannelLevelsOutcome::LevelsReset);
    }
}
