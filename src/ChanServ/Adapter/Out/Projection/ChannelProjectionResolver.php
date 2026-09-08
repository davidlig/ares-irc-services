<?php

declare(strict_types=1);

namespace App\ChanServ\Adapter\Out\Projection;

use App\ChanServ\Application\Port\In\ChannelAccessProjection;
use App\ChanServ\Application\Port\In\ChannelProjection;
use App\ChanServ\Application\Port\In\ChannelProjectionQuery;
use App\ChanServ\Application\Port\Out\ChannelAccessRepositoryInterface;
use App\ChanServ\Application\Port\Out\RegisteredChannelRepositoryInterface;
use App\ChanServ\Domain\Entity\RegisteredChannel;

final readonly class ChannelProjectionResolver implements ChannelProjectionQuery
{
    public function __construct(
        private RegisteredChannelRepositoryInterface $channels,
        private ChannelAccessRepositoryInterface $access,
    ) {}

    public function all(): array
    {
        return array_values(array_map($this->project(...), $this->channels->listAll()));
    }

    public function findByName(string $channelName): ?ChannelProjection
    {
        $channel = $this->channels->findByChannelName($channelName);

        return null !== $channel ? $this->project($channel) : null;
    }

    private function project(RegisteredChannel $channel): ChannelProjection
    {
        $access = array_values(array_map(
            static fn ($entry): ChannelAccessProjection => new ChannelAccessProjection($entry->getNickId(), $entry->getLevel()),
            $this->access->listByChannel($channel->getId()),
        ));

        return new ChannelProjection(
            $channel->getId(),
            $channel->getName(),
            $channel->getFounderNickId(),
            $channel->getTopic(),
            $channel->isMlockActive(),
            $channel->getMlock(),
            $channel->getMlockParams(),
            $channel->isTopicLock(),
            $channel->isForbidden(),
            $channel->getForbiddenReason(),
            $channel->isSuspended(),
            $channel->isPendingDeletion(),
            $access,
        );
    }
}
