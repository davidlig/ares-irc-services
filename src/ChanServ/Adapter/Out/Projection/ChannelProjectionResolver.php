<?php

declare(strict_types=1);

namespace App\ChanServ\Adapter\Out\Projection;

use App\ChanServ\Application\Port\In\ChannelProjection;
use App\ChanServ\Application\Port\In\ChannelProjectionQuery;
use App\ChanServ\Application\Port\Out\RegisteredChannelRepositoryInterface;
use App\ChanServ\Domain\Entity\RegisteredChannel;

final readonly class ChannelProjectionResolver implements ChannelProjectionQuery
{
    public function __construct(
        private RegisteredChannelRepositoryInterface $channels,
    ) {}

    public function all(): array
    {
        $projections = [];
        foreach ($this->channels->iterateAll() as $channel) {
            $projections[] = $this->project($channel);
        }

        return $projections;
    }

    public function findByName(string $channelName): ?ChannelProjection
    {
        $channel = $this->channels->findByChannelName($channelName);

        return null !== $channel ? $this->project($channel) : null;
    }

    private function project(RegisteredChannel $channel): ChannelProjection
    {
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
        );
    }
}
