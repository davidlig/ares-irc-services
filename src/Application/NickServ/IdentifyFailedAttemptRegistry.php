<?php

declare(strict_types=1);

namespace App\Application\NickServ;

use function count;

/**
 * In-memory registry of failed IDENTIFY attempts per client key.
 * Used to enforce a temporary lockout after too many failures within a time window.
 *
 * @see IdentifyCommand
 */
final class IdentifyFailedAttemptRegistry
{
    /** @var array<string, list<int>> client key -> list of failure timestamps (Unix) */
    private array $failuresByKey = [];

    public function recordFailedAttempt(string $clientKey, int $windowSeconds): void
    {
        $now = time();
        $cutoff = $now - $windowSeconds;

        $timestamps = $this->failuresByKey[$clientKey] ?? [];
        $timestamps[] = $now;
        $timestamps = array_values(array_filter($timestamps, static fn (int $t) => $t >= $cutoff));

        if ([] === $timestamps) {
            unset($this->failuresByKey[$clientKey]);

            return;
        }

        $this->failuresByKey[$clientKey] = $timestamps;
    }

    /**
     * Returns the number of seconds the client must wait before IDENTIFY is allowed again,
     * or 0 if not locked out.
     */
    public function getRemainingLockoutSeconds(
        string $clientKey,
        int $maxAttempts,
        int $windowSeconds,
        int $lockoutSeconds,
    ): int {
        if ($maxAttempts <= 0 || $lockoutSeconds <= 0) {
            return 0;
        }

        $now = time();
        $cutoff = $now - $windowSeconds;

        $timestamps = $this->failuresByKey[$clientKey] ?? [];
        $recent = array_values(array_filter($timestamps, static fn (int $t) => $t >= $cutoff));

        if (count($recent) < $maxAttempts) {
            return 0;
        }

        $lastFailure = max($recent);
        $lockoutUntil = $lastFailure + $lockoutSeconds;

        if ($now >= $lockoutUntil) {
            return 0;
        }

        return $lockoutUntil - $now;
    }

    public function clearFailedAttempts(string $clientKey): void
    {
        unset($this->failuresByKey[$clientKey]);
    }

    /**
     * Removes client keys whose failure timestamps are all outside the window.
     * Returns the number of keys removed. Used by maintenance to free memory.
     */
    public function pruneStale(int $windowSeconds): int
    {
        $now = time();
        $cutoff = $now - $windowSeconds;
        $removed = 0;

        foreach ($this->failuresByKey as $clientKey => $timestamps) {
            $recent = array_values(array_filter($timestamps, static fn (int $t) => $t >= $cutoff));
            if ([] === $recent) {
                unset($this->failuresByKey[$clientKey]);
                ++$removed;
            } else {
                $this->failuresByKey[$clientKey] = $recent;
            }
        }

        return $removed;
    }
}
