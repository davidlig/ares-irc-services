<?php

declare(strict_types=1);

namespace App\Domain\NickServ\Repository;

use App\Domain\NickServ\Entity\RegisteredNick;
use App\Domain\NickServ\ValueObject\NickStatus;

interface RegisteredNickRepositoryInterface
{
    public function save(RegisteredNick $nick): void;

    public function delete(RegisteredNick $nick): void;

    public function findByNick(string $nickname): ?RegisteredNick;

    /** Returns the account that has this vhost (user part). Null if not used. Used for uniqueness check. */
    public function findByVhost(string $vhost): ?RegisteredNick;

    /** Returns the account that has this email (case-insensitive). Null if not used or only FORBIDDEN. */
    public function findByEmail(string $email): ?RegisteredNick;

    public function existsByNick(string $nickname): bool;

    /**
     * Removes all PENDING entries whose expiresAt is in the past.
     * Returns the number of deleted records.
     */
    public function deleteExpiredPending(): int;

    /** @return RegisteredNick[] */
    public function findByStatus(NickStatus $status): array;

    /** @return RegisteredNick[] */
    public function all(): array;
}
