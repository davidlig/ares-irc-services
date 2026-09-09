<?php

declare(strict_types=1);

namespace App\ChanServ\Application\UseCase\CleanupDroppedNick;

use App\ChanServ\Application\Port\Out\ChannelAccessRepositoryInterface;
use App\ChanServ\Application\Port\Out\ChannelAkickRepositoryInterface;
use App\ChanServ\Application\Port\Out\ChanServEventPublisher;
use App\ChanServ\Application\Port\Out\ChanTransactionBoundary;
use App\ChanServ\Application\Port\Out\NickDropCleanupActivitySink;
use App\ChanServ\Application\Port\Out\RegisteredChannelRepositoryInterface;
use App\ChanServ\Application\PublishedEvent\ChannelDropCleanupEvent;
use App\ChanServ\Application\PublishedEvent\ChannelDropEvent;
use App\ChanServ\Domain\Entity\RegisteredChannel;
use DateTimeImmutable;

final readonly class CleanupDroppedNickDataHandler
{
    public function __construct(
        private ChannelAccessRepositoryInterface $channelAccessRepository,
        private ChannelAkickRepositoryInterface $channelAkickRepository,
        private RegisteredChannelRepositoryInterface $channelRepository,
        private ChanServEventPublisher $eventPublisher,
        private ChanTransactionBoundary $transactionBoundary,
        private NickDropCleanupActivitySink $activitySink,
    ) {}

    public function handle(CleanupDroppedNickData $command): void
    {
        $nickId = $command->nickId;

        $this->channelAccessRepository->deleteByNickId($nickId);
        $this->channelAkickRepository->clearCreatorNickId($nickId);
        $this->channelRepository->clearSuccessorNickId($nickId);

        foreach ($this->channelRepository->findByFounderNickId($nickId) as $channel) {
            $this->handleFounderDrop($channel, $command->occurredAt);
        }
    }

    private function handleFounderDrop(RegisteredChannel $channel, DateTimeImmutable $occurredAt): void
    {
        $successorNickId = $channel->getSuccessorNickId();

        if (null !== $successorNickId) {
            $channel->changeFounder($successorNickId);
            $this->channelRepository->save($channel);
            $this->activitySink->founderTransferred(
                $channel->getId(),
                $channel->getName(),
                $successorNickId,
            );

            return;
        }

        $cleanupEvent = new ChannelDropCleanupEvent(
            channelId: $channel->getId(),
            occurredAt: $occurredAt,
            channelName: $channel->getName(),
            channelNameLower: $channel->getNameLower(),
            reason: 'founder_dropped',
        );

        $this->eventPublisher->publish($cleanupEvent);
        $this->channelRepository->delete($channel);

        $this->transactionBoundary->afterCommit(function () use ($cleanupEvent): void {
            $this->eventPublisher->publish(new ChannelDropEvent(
                $cleanupEvent->channelId,
                $cleanupEvent->channelName,
                $cleanupEvent->channelNameLower,
                $cleanupEvent->reason,
                $cleanupEvent->occurredAt,
            ));
        });

        $this->activitySink->channelDropped($channel->getId(), $channel->getName());
    }
}
