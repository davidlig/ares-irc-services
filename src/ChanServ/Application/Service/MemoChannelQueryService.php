<?php

declare(strict_types=1);

namespace App\ChanServ\Application\Service;

use App\ChanServ\Application\Port\In\MemoChannel;
use App\ChanServ\Application\Port\In\MemoChannelQuery;
use App\ChanServ\Application\Port\Out\RegisteredChannelRepositoryInterface;
use App\ChanServ\Domain\Entity\ChannelLevel;
use App\ChanServ\Domain\Entity\RegisteredChannel;

use function array_find;
use function strtolower;

final readonly class MemoChannelQueryService implements MemoChannelQuery
{
    public function __construct(
        private RegisteredChannelRepositoryInterface $channelRepository,
        private ChanServAccessHelper $accessHelper,
    ) {}

    public function findByName(string $channelName): ?MemoChannel
    {
        return $this->toMemoChannel(
            $this->available($this->channelRepository->findByChannelName(strtolower($channelName))),
        );
    }

    public function canRead(int $channelId, int $nickId): bool
    {
        return $this->hasRequiredLevel($channelId, $nickId, ChannelLevel::KEY_MEMOREAD);
    }

    public function canManage(int $channelId, int $nickId): bool
    {
        return $this->hasRequiredLevel($channelId, $nickId, ChannelLevel::KEY_MEMOCHANGE);
    }

    public function isFounder(int $channelId, int $nickId): bool
    {
        $channel = $this->findAvailableById($channelId);

        return null !== $channel && $channel->isFounder($nickId);
    }

    private function hasRequiredLevel(int $channelId, int $nickId, string $levelKey): bool
    {
        $channel = $this->findAvailableById($channelId);
        if (null === $channel) {
            return false;
        }

        return $this->accessHelper->effectiveAccessLevel($channel, $nickId, true)
            >= $this->accessHelper->getLevelValue($channel->getId(), $levelKey);
    }

    private function findAvailableById(int $channelId): ?RegisteredChannel
    {
        $channel = array_find(
            $this->channelRepository->findByIds([$channelId]),
            static fn (RegisteredChannel $candidate): bool => $candidate->getId() === $channelId,
        );

        return $this->available($channel);
    }

    private function available(?RegisteredChannel $channel): ?RegisteredChannel
    {
        return null === $channel || $channel->isBlocked() ? null : $channel;
    }

    private function toMemoChannel(?RegisteredChannel $channel): ?MemoChannel
    {
        return null === $channel ? null : new MemoChannel($channel->getId(), $channel->getName());
    }
}
