<?php

declare(strict_types=1);

namespace App\NickServ\Application\Port\Out;

use App\NickServ\Domain\Entity\RegisteredNick;
use App\NickServ\Domain\ValueObject\NickStatus;
use DateTimeImmutable;

interface RegisteredNickRepositoryInterface
{
    public function save(RegisteredNick $nick): void;

    /** @param list<RegisteredNick> $nicks Managed accounts with updated activity. */
    public function saveActivityBatch(array $nicks): void;

    public function delete(RegisteredNick $nick): void;

    /**
     * Resolves a nickname to its registration: the stored RFC 1459 key must match and the stored
     * nickname must be equivalent under the casemapping the IRCd negotiated.
     */
    public function findByNick(string $nickname): ?RegisteredNick;

    /**
     * Returns the registration that owns the nickname's RFC 1459 key, whatever the negotiated
     * casemapping. Only for uniqueness checks and writes that must not collide with the key.
     */
    public function findByNicknameKey(string $nickname): ?RegisteredNick;

    /**
     * @param list<string> $nicknames
     *
     * @return array<string, RegisteredNick> keyed by each queried nickname that resolves as findByNick() does
     */
    public function findByNicks(array $nicknames): array;

    /** @param list<string> $nicknames
     * @return array<string, RegisteredNick> keyed by each queried nickname that resolves as findByNick() does
     */
    public function findByNicknamesLower(array $nicknames): array;

    public function findById(int $id): ?RegisteredNick;

    /** @param list<int> $ids
     * @return array<int, string> nicknames keyed by account ID
     */
    public function findNicknamesByIds(array $ids): array;

    /** Returns the account that has this vhost (user part). Null if not used. Used for uniqueness check. */
    public function findByVhost(string $vhost): ?RegisteredNick;

    /** Returns the account that has this email (case-insensitive). Null if not used or only FORBIDDEN. */
    public function findByEmail(string $email): ?RegisteredNick;

    /** True when the nickname's RFC 1459 key is taken (uniqueness), whatever the negotiated casemapping. */
    public function existsByNick(string $nickname): bool;

    /**
     * Returns REGISTERED nicks whose last activity (lastSeenAt ?? registeredAt) is before the threshold.
     * Used for inactivity purge; excludes PENDING, SUSPENDED, FORBIDDEN.
     *
     * @return RegisteredNick[]
     */
    public function findRegisteredInactiveSince(DateTimeImmutable $threshold, ?int $limit = null): array;

    /**
     * Removes all PENDING entries whose expiresAt is in the past.
     * Returns the number of deleted records.
     */
    public function deleteExpiredPending(DateTimeImmutable $now): int;

    /**
     * Returns SUSPENDED nicks whose suspendedUntil is in the past.
     * Used for temporary suspension expiry; excludes permanent suspensions (suspendedUntil = null).
     *
     * @return RegisteredNick[]
     */
    public function findExpiredSuspensions(DateTimeImmutable $now, ?int $limit = null): array;

    /**
     * Returns nicks manually dropped before the threshold and ready for hard deletion.
     *
     * @return RegisteredNick[]
     */
    public function findPendingDeletionBefore(DateTimeImmutable $threshold, ?int $limit = null): array;

    /** @return RegisteredNick[] */
    public function findByStatus(NickStatus $status): array;

    /** @return RegisteredNick[] */
    public function all(): array;

    /**
     * Counts every nickname whose canonical name matches a case-insensitive, strict star glob.
     * The only wildcard in the supplied pattern is '*'.
     */
    public function countByPattern(string $pattern): int;

    /**
     * Returns a bounded, stable ascending page of all nickname statuses matching the glob.
     *
     * @return list<RegisteredNick>
     */
    public function searchByPattern(string $pattern, int $offset, int $limit): array;

    /** @return list<string> */
    public function findNicknamesByLastConnectIp(string $ip): array;
}
