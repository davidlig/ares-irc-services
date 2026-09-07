<?php

declare(strict_types=1);

namespace App\Application\ChanServ\Port\In;

interface MemoChannelQuery
{
    public function findByName(string $channelName): ?MemoChannel;

    public function canRead(int $channelId, int $nickId): bool;

    public function canManage(int $channelId, int $nickId): bool;

    public function isFounder(int $channelId, int $nickId): bool;
}
