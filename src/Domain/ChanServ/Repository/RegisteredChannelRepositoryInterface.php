<?php

declare(strict_types=1);

namespace App\Domain\ChanServ\Repository;

use App\Domain\ChanServ\Entity\RegisteredChannel;
use DateTimeImmutable;

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

    /**
     * Clear successor reference on all channels where the given nick is successor.
     * Used when a nick is dropped.
     */
    public function clearSuccessorNickId(int $successorNickId): void;

    /** @return RegisteredChannel[] All registered channels (e.g. for ChanServ rejoin on burst). */
    public function listAll(): array;

    /**
     * @param int[] $ids
     *
     * @return RegisteredChannel[]
     */
    public function findByIds(array $ids): array;

    /**
     * @return RegisteredChannel[] Channels inactive since the given threshold (lastUsedAt or createdAt < threshold)
     */
    public function findRegisteredInactiveSince(DateTimeImmutable $threshold): array;

    /**
     * @return RegisteredChannel[] Channels that are currently suspended and their suspension has expired
     */
    public function findExpiredSuspensions(): array;

    /**
     * @return RegisteredChannel[] Channels that are currently forbidden
     */
    public function findForbiddenChannels(): array;
}
