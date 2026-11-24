<?php

declare(strict_types=1);

namespace App\ChanServ\Application\Port\Out;

use App\ChanServ\Domain\Entity\RegisteredChannel;

interface RegisteredChannelRepositoryInterface
{
    public function save(RegisteredChannel $channel): void;

    public function delete(RegisteredChannel $channel): void;

    /**
     * Resolves a channel name to its registration: the stored RFC 1459 key must match and the
     * stored name must be equivalent under the casemapping the IRCd negotiated.
     */
    public function findByChannelName(string $channelName): ?RegisteredChannel;

    /**
     * Returns the registration that owns the name's RFC 1459 key, whatever the negotiated
     * casemapping. Only for uniqueness checks and writes that must not collide with the key.
     */
    public function findByChannelNameKey(string $channelName): ?RegisteredChannel;

    /** Counts listed channels matching a strict star glob, excluding pending/rejected registrations. */
    public function countByPattern(string $pattern): int;

    /** @return list<RegisteredChannel> bounded, stable ascending page matching the strict star glob, excluding pending/rejected registrations */
    public function searchByPattern(string $pattern, int $offset, int $limit): array;

    /** True when the name's RFC 1459 key is taken (uniqueness), whatever the negotiated casemapping. */
    public function existsByChannelName(string $channelName): bool;

    /** @return RegisteredChannel[] */
    public function findByFounderNickId(int $founderNickId): array;

    public function countByFounderNickId(int $founderNickId, bool $excludeRejected = false): int;

    /** @return list<RegisteredChannel> Pending registrations ordered by name and ID. */
    public function findPendingPage(int $offset, int $limit): array;

    /** @return RegisteredChannel[] Founder associations ordered by name, excluding pending/rejected registrations. */
    public function findEstablishedByFounderNickId(int $founderNickId): array;

    /** @return RegisteredChannel[] Successor associations ordered by name, excluding pending/rejected registrations. */
    public function findEstablishedBySuccessorNickId(int $successorNickId): array;

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
    public function iterateRegisteredInactiveSince(\DateTimeImmutable $threshold): iterable;

    /**
     * Entities are detached from the identity map as the iterator advances; persist and flush per item when mutating.
     *
     * @return iterable<RegisteredChannel>
     */
    public function iterateExpiredSuspensions(\DateTimeImmutable $now): iterable;

    /**
     * Entities are detached from the identity map as the iterator advances; persist and flush per item when mutating.
     *
     * @return iterable<RegisteredChannel>
     */
    public function iteratePendingDeletionBefore(\DateTimeImmutable $threshold): iterable;

    /**
     * @param int[] $ids
     *
     * @return RegisteredChannel[]
     */
    public function findByIds(array $ids): array;
}
