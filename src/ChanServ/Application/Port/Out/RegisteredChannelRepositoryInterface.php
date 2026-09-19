<?php

declare(strict_types=1);

namespace App\ChanServ\Application\Port\Out;

use App\ChanServ\Domain\Entity\RegisteredChannel;
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

    /**
     * Entities are detached from the identity map as the iterator advances; persist and flush per item when mutating.
     *
     * @return iterable<RegisteredChannel> all registered channels ordered by name, in bounded batches
     */
    public function iterateAll(): iterable;

    /**
     * Entities are detached from the identity map as the iterator advances; persist and flush per item when mutating.
     *
     * @return iterable<RegisteredChannel>
     */
    public function iterateRegisteredInactiveSince(DateTimeImmutable $threshold): iterable;

    /**
     * Entities are detached from the identity map as the iterator advances; persist and flush per item when mutating.
     *
     * @return iterable<RegisteredChannel>
     */
    public function iterateExpiredSuspensions(DateTimeImmutable $now): iterable;

    /**
     * Entities are detached from the identity map as the iterator advances; persist and flush per item when mutating.
     *
     * @return iterable<RegisteredChannel>
     */
    public function iteratePendingDeletionBefore(DateTimeImmutable $threshold): iterable;

    /**
     * @param int[] $ids
     *
     * @return RegisteredChannel[]
     */
    public function findByIds(array $ids): array;

    /**
     * @return RegisteredChannel[] Channels that are currently forbidden
     */
    public function findForbiddenChannels(): array;
}
