<?php

declare(strict_types=1);

namespace App\Domain\NickServ\Repository;

use App\Domain\NickServ\Entity\RegisteredNick;
use App\Domain\NickServ\ValueObject\NickStatus;
use DateTimeImmutable;

interface RegisteredNickRepositoryInterface
{
    public function save(RegisteredNick $nick): void;

    public function delete(RegisteredNick $nick): void;

    public function findByNick(string $nickname): ?RegisteredNick;

    public function findById(int $id): ?RegisteredNick;

    /** Returns the account that has this vhost (user part). Null if not used. Used for uniqueness check. */
    public function findByVhost(string $vhost): ?RegisteredNick;

    /** Returns the account that has this email (case-insensitive). Null if not used or only FORBIDDEN. */
    public function findByEmail(string $email): ?RegisteredNick;

    public function existsByNick(string $nickname): bool;

    /**
     * Returns REGISTERED nicks whose last activity (lastSeenAt ?? registeredAt) is before the threshold.
     * Used for inactivity purge; excludes PENDING, SUSPENDED, FORBIDDEN.
     *
     * @return RegisteredNick[]
     */
    public function findRegisteredInactiveSince(DateTimeImmutable $threshold): array;

    /**
     * Removes all PENDING entries whose expiresAt is in the past.
     * Returns the number of deleted records.
     */
    public function deleteExpiredPending(): int;

    /**
     * Returns SUSPENDED nicks whose suspendedUntil is in the past.
     * Used for temporary suspension expiry; excludes permanent suspensions (suspendedUntil = null).
     *
     * @return RegisteredNick[]
     */
    public function findExpiredSuspensions(): array;

    /** @return RegisteredNick[] */
    public function findByStatus(NickStatus $status): array;

    /** @return RegisteredNick[] */
    public function all(): array;
}
