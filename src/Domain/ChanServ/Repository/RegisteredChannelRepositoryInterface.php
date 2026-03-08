<?php

declare(strict_types=1);

namespace App\Domain\ChanServ\Repository;

use App\Domain\ChanServ\Entity\RegisteredChannel;

interface RegisteredChannelRepositoryInterface
{
    public function save(RegisteredChannel $channel): void;

    public function delete(RegisteredChannel $channel): void;

    public function findByChannelName(string $channelName): ?RegisteredChannel;

    public function existsByChannelName(string $channelName): bool;

    /** @return RegisteredChannel[] */
    public function findByFounderNickId(int $founderNickId): array;

    /**
     * @return RegisteredChannel[] Channels where user is successor
     */
    public function findBySuccessorNickId(int $successorNickId): array;

    /** @return RegisteredChannel[] All registered channels (e.g. for ChanServ rejoin on burst). */
    public function listAll(): array;

    /**
     * @param int[] $ids
     * @return RegisteredChannel[]
     */
    public function findByIds(array $ids): array;
}
