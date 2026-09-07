<?php

declare(strict_types=1);

namespace App\MemoServ\Adapter\Out\ChanServ;

use App\Application\ChanServ\Port\In\MemoChannelQuery;
use App\MemoServ\Application\Model\MemoChannelView;
use App\MemoServ\Application\Port\Out\MemoChannelPort;

final readonly class ChanServMemoChannelAdapter implements MemoChannelPort
{
    public function __construct(private MemoChannelQuery $channelQuery) {}

    public function findChannelByName(string $channelName): ?MemoChannelView
    {
        $channel = $this->channelQuery->findByName($channelName);
        if (null === $channel) {
            return null;
        }

        return new MemoChannelView($channel->id, $channel->name);
    }

    public function isChannelFounder(int $channelId, int $userNickId): bool
    {
        return $this->channelQuery->isFounder($channelId, $userNickId);
    }

    public function canReadChannelMemos(int $channelId, int $userNickId): bool
    {
        return $this->channelQuery->canRead($channelId, $userNickId);
    }

    public function canManageChannelMemos(int $channelId, int $userNickId): bool
    {
        return $this->channelQuery->canManage($channelId, $userNickId);
    }
}
