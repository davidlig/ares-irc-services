<?php

declare(strict_types=1);

namespace App\MemoServ\Application\Port\Out;

use App\MemoServ\Application\Model\MemoChannelView;

interface MemoChannelPort
{
    public function findChannelByName(string $channelName): ?MemoChannelView;

    /**
     * Asserts user has read access for channel memos. Throws InsufficientAccessException if not.
     */
    public function requireReadAccess(int $channelId, int $userNickId, string $channelName, string $operation): void;

    /**
     * Asserts user has change access for channel memos. Throws InsufficientAccessException if not.
     */
    public function requireManageAccess(int $channelId, int $userNickId, string $channelName, string $operation): void;

    public function isChannelFounder(int $channelId, int $userNickId): bool;

    public function canReadChannelMemos(int $channelId, int $userNickId): bool;
}
