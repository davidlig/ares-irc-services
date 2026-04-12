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

    /**
     * @return ChannelAccess[] All access entries for a nick
     */
    public function findByNick(int $nickId): array;

    /**
     * Delete all access entries for a nick (used when nick is dropped).
     */
    public function deleteByNickId(int $nickId): void;

    /**
     * Delete all access entries for a channel and return the number of deleted entries.
     */
    public function deleteByChannelId(int $channelId): int;
}
