<?php

declare(strict_types=1);

namespace App\ChanServ\Application\UseCase\UpdateSetting;

use App\ChanServ\Application\Port\Out\ChanUserAccountPort;
use App\ChanServ\Application\Port\Out\RegisteredChannelRepositoryInterface;
use App\ChanServ\Application\PublishedEvent\ChannelSuccessorChangedEvent;
use App\ChanServ\Application\PublishedEvent\ChannelTopiclockUpdatedEvent;
use App\Shared\Application\Port\EventBusInterface;
use InvalidArgumentException;

use function filter_var;
use function trim;

use const FILTER_VALIDATE_EMAIL;

final readonly class UpdateChannelSettingHandler implements UpdateChannelSettingHandlerInterface
{
    public function __construct(
        private RegisteredChannelRepositoryInterface $channels,
        private ChanUserAccountPort $accounts,
        private EventBusInterface $events,
    ) {}

    public function handle(UpdateChannelSetting $command): UpdateChannelSettingResult
    {
        return match ($command->setting) {
            ChannelSetting::Description => $this->description($command),
            ChannelSetting::Email => $this->email($command),
            ChannelSetting::EntryMessage => $this->entryMessage($command),
            ChannelSetting::Successor => $this->successor($command),
            ChannelSetting::TopicLock => $this->topicLock($command),
            ChannelSetting::Url => $this->url($command),
        };
    }

    private function description(UpdateChannelSetting $command): UpdateChannelSettingResult
    {
        $description = trim($command->value);
        if ('' === $description) {
            return new UpdateChannelSettingResult(UpdateChannelSettingOutcome::MissingValue);
        }
        $command->channel->updateDescription($description);
        $this->channels->save($command->channel);

        return new UpdateChannelSettingResult(UpdateChannelSettingOutcome::Updated);
    }

    private function email(UpdateChannelSetting $command): UpdateChannelSettingResult
    {
        $email = trim($command->value);
        if ('' !== $email && false === filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return new UpdateChannelSettingResult(UpdateChannelSettingOutcome::InvalidEmail);
        }
        $command->channel->updateEmail('' === $email ? null : $email);
        $this->channels->save($command->channel);

        return new UpdateChannelSettingResult('' === $email ? UpdateChannelSettingOutcome::Cleared : UpdateChannelSettingOutcome::Updated);
    }

    private function entryMessage(UpdateChannelSetting $command): UpdateChannelSettingResult
    {
        try {
            $command->channel->updateEntrymsg($command->value);
        } catch (InvalidArgumentException) {
            return new UpdateChannelSettingResult(UpdateChannelSettingOutcome::EntryMessageTooLong);
        }
        $this->channels->save($command->channel);

        return new UpdateChannelSettingResult(UpdateChannelSettingOutcome::Updated);
    }

    private function successor(UpdateChannelSetting $command): UpdateChannelSettingResult
    {
        $nickname = trim($command->value);
        if ('' === $nickname) {
            $this->assignSuccessor($command, null);

            return new UpdateChannelSettingResult(UpdateChannelSettingOutcome::Cleared);
        }
        $account = $this->accounts->findAccountByNick($nickname);
        if (null === $account) {
            return new UpdateChannelSettingResult(UpdateChannelSettingOutcome::SuccessorNotFound, $nickname);
        }
        if ($account->suspended) {
            return new UpdateChannelSettingResult(UpdateChannelSettingOutcome::SuccessorSuspended, $nickname);
        }
        if (!$account->registered) {
            return new UpdateChannelSettingResult(UpdateChannelSettingOutcome::SuccessorNotRegistered, $nickname);
        }
        if ($command->channel->isFounder($account->id)) {
            return new UpdateChannelSettingResult(UpdateChannelSettingOutcome::SuccessorIsFounder, $nickname);
        }
        $this->assignSuccessor($command, $account->id);

        return new UpdateChannelSettingResult(UpdateChannelSettingOutcome::Updated, $nickname);
    }

    private function assignSuccessor(UpdateChannelSetting $command, ?int $successorId): void
    {
        $oldSuccessorId = $command->channel->getSuccessorNickId();
        $command->channel->assignSuccessor($successorId);
        $this->channels->save($command->channel);
        $this->events->dispatch(new ChannelSuccessorChangedEvent(
            channelId: $command->channel->getId(),
            channelName: $command->channel->getName(),
            oldSuccessorNickId: $oldSuccessorId,
            newSuccessorNickId: $successorId,
            performedBy: $command->actorNickname,
            performedByNickId: $command->actorAccountId,
            performedByIp: $command->actorIp,
            performedByHost: $command->actorHost,
            occurredAt: $command->occurredAt,
        ));
    }

    private function topicLock(UpdateChannelSetting $command): UpdateChannelSettingResult
    {
        $enabled = 'ON' === $command->value;
        $command->channel->configureTopicLock($enabled);
        $this->channels->save($command->channel);
        $this->events->dispatch(new ChannelTopiclockUpdatedEvent($command->channel->getName()));

        return new UpdateChannelSettingResult(UpdateChannelSettingOutcome::Updated);
    }

    private function url(UpdateChannelSetting $command): UpdateChannelSettingResult
    {
        $url = trim($command->value);
        $command->channel->updateUrl('' === $url ? null : $url);
        $this->channels->save($command->channel);

        return new UpdateChannelSettingResult('' === $url ? UpdateChannelSettingOutcome::Cleared : UpdateChannelSettingOutcome::Updated);
    }
}
