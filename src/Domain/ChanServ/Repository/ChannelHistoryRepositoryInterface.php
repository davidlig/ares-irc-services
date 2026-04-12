<?php

declare(strict_types=1);

namespace App\Domain\ChanServ\Repository;

use App\Domain\ChanServ\Entity\ChannelHistory;
use DateTimeImmutable;

interface ChannelHistoryRepositoryInterface
{
    public function save(ChannelHistory $history): void;

    public function findById(int $id): ?ChannelHistory;

    /**
     * @return ChannelHistory[]
     */
    public function findByChannelId(int $channelId, ?int $limit = null, int $offset = 0): array;

    public function countByChannelId(int $channelId): int;

    public function deleteById(int $id): bool;

    public function deleteByChannelId(int $channelId): int;

    public function deleteOlderThan(DateTimeImmutable $threshold): int;
}
