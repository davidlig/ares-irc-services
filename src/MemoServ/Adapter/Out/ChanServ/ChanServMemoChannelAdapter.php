<?php

declare(strict_types=1);

namespace App\MemoServ\Adapter\Out\ChanServ;

use App\Application\ChanServ\ChanServAccessHelper;
use App\Domain\ChanServ\Entity\ChannelLevel;
use App\Domain\ChanServ\Entity\RegisteredChannel;
use App\Domain\ChanServ\Repository\RegisteredChannelRepositoryInterface;
use App\MemoServ\Application\Model\MemoChannelView;
use App\MemoServ\Application\Port\Out\MemoChannelPort;

use function strtolower;

final readonly class ChanServMemoChannelAdapter implements MemoChannelPort
{
    public function __construct(
        private RegisteredChannelRepositoryInterface $channelRepository,
        private ChanServAccessHelper $accessHelper,
    ) {}

    public function findChannelByName(string $channelName): ?MemoChannelView
    {
        $channel = $this->channelRepository->findByChannelName(strtolower($channelName));
        if (null === $channel) {
            return null;
        }

        return new MemoChannelView($channel->getId(), $channel->getName());
    }

    public function requireReadAccess(int $channelId, int $userNickId, string $channelName, string $operation): void
    {
        $channel = $this->getChannel($channelId);
        if (null !== $channel) {
            $this->accessHelper->requireLevel($channel, $userNickId, ChannelLevel::KEY_MEMOREAD, $channelName, $operation);
        }
    }

    public function requireManageAccess(int $channelId, int $userNickId, string $channelName, string $operation): void
    {
        $channel = $this->getChannel($channelId);
        if (null !== $channel) {
            $this->accessHelper->requireLevel($channel, $userNickId, ChannelLevel::KEY_MEMOCHANGE, $channelName, $operation);
        }
    }

    public function isChannelFounder(int $channelId, int $userNickId): bool
    {
        $channel = $this->getChannel($channelId);

        return null !== $channel && $channel->getFounderNickId() === $userNickId;
    }

    public function canReadChannelMemos(int $channelId, int $userNickId): bool
    {
        $channel = $this->getChannel($channelId);
        if (null === $channel) {
            return false;
        }

        return $this->accessHelper->effectiveAccessLevel($channel, $userNickId, true)
            >= $this->accessHelper->getLevelValue($channel->getId(), ChannelLevel::KEY_MEMOREAD);
    }

    private function getChannel(int $channelId): ?RegisteredChannel
    {
        $channels = $this->channelRepository->findByIds([$channelId]);

        return $channels[0] ?? null;
    }
}
