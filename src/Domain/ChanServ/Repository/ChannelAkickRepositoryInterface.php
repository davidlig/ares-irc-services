<?php

declare(strict_types=1);

namespace App\Domain\ChanServ\Repository;

use App\Domain\ChanServ\Entity\ChannelAkick;

interface ChannelAkickRepositoryInterface
{
    public function save(ChannelAkick $akick): void;

    public function remove(ChannelAkick $akick): void;

    public function findById(int $id): ?ChannelAkick;

    /**
     * @return ChannelAkick[] All AKICK entries for a channel, ordered by creation date
     */
    public function listByChannel(int $channelId): array;

    public function findByChannelAndMask(int $channelId, string $mask): ?ChannelAkick;

    public function countByChannel(int $channelId): int;

    /**
     * @return ChannelAkick[] All AKICK entries that have expired
     */
    public function findExpired(): array;

    /**
     * @return ChannelAkick[] All AKICK entries for channels belonging to a nick (for cleanup on nick drop)
     */
    public function findByChannelIds(array $channelIds): array;
}
