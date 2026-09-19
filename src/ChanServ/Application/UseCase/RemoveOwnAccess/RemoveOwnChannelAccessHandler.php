<?php

declare(strict_types=1);

namespace App\ChanServ\Application\UseCase\RemoveOwnAccess;

use App\ChanServ\Application\Port\Out\ChannelAccessRepositoryInterface;
use App\ChanServ\Application\Port\Out\RegisteredChannelRepositoryInterface;
use App\ChanServ\Application\PublishedEvent\ChannelAccessChangedEvent;
use App\Shared\Application\Port\EventBusInterface;

use function strtolower;

final readonly class RemoveOwnChannelAccessHandler implements RemoveOwnChannelAccessHandlerInterface
{
    public function __construct(
        private RegisteredChannelRepositoryInterface $channels,
        private ChannelAccessRepositoryInterface $accessEntries,
        private EventBusInterface $events,
    ) {}

    public function handle(RemoveOwnChannelAccess $command): RemoveOwnChannelAccessOutcome
    {
        $channel = $this->channels->findByChannelName(strtolower($command->channelName));
        if (null === $channel) {
            return RemoveOwnChannelAccessOutcome::NotRegistered;
        }
        if (!$command->founderEquivalent && $channel->isFounder($command->accountId)) {
            return RemoveOwnChannelAccessOutcome::FounderNotInAccess;
        }
        $entry = $this->accessEntries->findByChannelAndNick($channel->getId(), $command->accountId);
        if (null === $entry) {
            return RemoveOwnChannelAccessOutcome::NotInAccess;
        }

        $this->accessEntries->remove($entry);
        $this->events->dispatch(new ChannelAccessChangedEvent(
            channelId: $channel->getId(),
            channelName: $command->channelName,
            action: 'DEL',
            targetNickId: $command->accountId,
            targetNickname: $command->nickname,
            level: null,
            performedBy: $command->nickname,
            performedByNickId: $command->accountId,
            performedByIp: $command->actorIp,
            performedByHost: $command->actorHost,
            occurredAt: $command->occurredAt,
        ));

        return RemoveOwnChannelAccessOutcome::Removed;
    }
}
