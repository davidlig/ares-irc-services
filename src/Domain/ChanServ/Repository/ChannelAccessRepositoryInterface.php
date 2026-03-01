<?php

declare(strict_types=1);

namespace App\Domain\ChanServ\Repository;

use App\Domain\ChanServ\Entity\ChannelAccess;

interface ChannelAccessRepositoryInterface
{
    public function save(ChannelAccess $access): void;

    public function remove(ChannelAccess $access): void;

    public function findByChannelAndNick(int $channelId, int $nickId): ?ChannelAccess;

    /**
     * @return ChannelAccess[] Ordered by level descending
     */
    public function listByChannel(int $channelId): array;

    public function countByChannel(int $channelId): int;
}
