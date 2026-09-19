<?php

declare(strict_types=1);

namespace App\MemoServ\Application\Port\Out;

use App\MemoServ\Application\Model\MemoChannelView;

interface MemoChannelPort
{
    public function findChannelByName(string $channelName): ?MemoChannelView;

    public function isChannelFounder(int $channelId, int $userNickId): bool;

    public function canReadChannelMemos(int $channelId, int $userNickId): bool;

    public function canManageChannelMemos(int $channelId, int $userNickId): bool;
}
