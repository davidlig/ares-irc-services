<?php

declare(strict_types=1);

namespace App\NickServ\Application\Port\Out;

use App\NickServ\Domain\Entity\BlockedEmailDomain;

/**
 * Persists the e-mail domains on which user nickname registration is refused.
 *
 * Listings are ordered by domain ascending so the 1-based positions shown to
 * operators remain stable between ADD and DEL.
 */
interface BlockedEmailDomainRepositoryInterface
{
    public function isBlocked(string $domain): bool;

    public function add(BlockedEmailDomain $blockedEmailDomain): void;

    /** Removes the entry at the given 1-based position and returns it, or null when absent. */
    public function removeAtPosition(int $position): ?BlockedEmailDomain;

    /** @return int number of entries removed */
    public function removeAll(): int;

    /** @return list<BlockedEmailDomain> */
    public function listAll(): array;
}
