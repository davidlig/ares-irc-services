<?php

declare(strict_types=1);

namespace App\Application\Services\Antiflood;

use function count;

/**
 * In-memory antiflood registry for IRC service commands.
 *
 * Tracks command timestamps per client key AND stores an absolute lockout deadline.
 * When a user exceeds maxMessages within windowSeconds, their key gets a lockoutUntil
 * timestamp (now + cooldownSeconds). Until that deadline passes, ALL commands are
 * blocked — even if the sliding window resets.
 *
 * This prevents the bug where a short windowSeconds would allow commands after the
 * window expires but before the cooldown period ends.
 *
 * IRCops (isOper) are exempt — checked by the subscriber, not here.
 */
final class AntifloodRegistry
{
    /** @var array<string, list<int>> client key -> list of command timestamps (Unix) */
    private array $timestampsByKey = [];

    /** @var array<string, int> client key -> lockout deadline timestamp (Unix) */
    private array $lockoutUntilByKey = [];

    /** @var array<string, true> client keys that have already received a lockout notice */
    private array $notifiedByKey = [];

    /**
     * Records a command attempt for the given client key.
     * Prunes timestamps outside the window to keep memory bounded.
     */
    public function recordCommand(string $clientKey, int $windowSeconds): void
    {
        $now = time();
        $cutoff = $now - $windowSeconds;

        $timestamps = $this->timestampsByKey[$clientKey] ?? [];
        $timestamps[] = $now;
        $timestamps = array_values(array_filter($timestamps, static fn (int $t): bool => $t >= $cutoff));

        if ([] === $timestamps) {
            unset($this->timestampsByKey[$clientKey]);

            return;
        }

        $this->timestampsByKey[$clientKey] = $timestamps;
    }

    /**
     * Returns the number of seconds the client must wait before commands are allowed again,
     * or 0 if not locked out (commands are allowed).
     *
     * Logic:
     * 1. If maxMessages <= 0 or lockoutSeconds <= 0 → disabled, return 0
     * 2. Check absolute lockout deadline first — if still locked out, return remaining
     * 3. Filter timestamps to only those within $windowSeconds of now
     * 4. If count(recent) < maxMessages → not locked out, return 0
     * 5. User exceeded limit → set absolute lockout deadline (now + lockoutSeconds)
     * 6. Return lockoutUntil - now (remaining lockout seconds)
     */
    public function getRemainingLockoutSeconds(
        string $clientKey,
        int $maxMessages,
        int $windowSeconds,
        int $lockoutSeconds,
    ): int {
        if ($maxMessages <= 0 || $lockoutSeconds <= 0) {
            return 0;
        }

        $now = time();

        if (isset($this->lockoutUntilByKey[$clientKey])) {
            $lockoutUntil = $this->lockoutUntilByKey[$clientKey];
            if ($now < $lockoutUntil) {
                return $lockoutUntil - $now;
            }
            unset($this->lockoutUntilByKey[$clientKey]);
            unset($this->notifiedByKey[$clientKey]);
        }

        $cutoff = $now - $windowSeconds;
        $timestamps = $this->timestampsByKey[$clientKey] ?? [];
        $recent = array_values(array_filter($timestamps, static fn (int $t): bool => $t >= $cutoff));

        if (count($recent) < $maxMessages) {
            return 0;
        }

        $lastCommand = max($recent);
        $lockoutUntil = $lastCommand + $lockoutSeconds;
        $this->lockoutUntilByKey[$clientKey] = $lockoutUntil;

        return $lockoutUntil - $now;
    }

    /**
     * Removes stale client keys whose timestamps are all outside the window
     * AND whose lockout has expired. Returns the number of keys removed.
     * Used by maintenance to free memory.
     */
    public function pruneStale(int $windowSeconds): int
    {
        $now = time();
        $cutoff = $now - $windowSeconds;
        $removed = 0;

        foreach ($this->timestampsByKey as $clientKey => $timestamps) {
            $recent = array_values(array_filter($timestamps, static fn (int $t): bool => $t >= $cutoff));

            $lockoutUntil = $this->lockoutUntilByKey[$clientKey] ?? 0;
            $isLockedOut = $now < $lockoutUntil;

            if ([] === $recent && !$isLockedOut) {
                unset($this->timestampsByKey[$clientKey]);
                unset($this->lockoutUntilByKey[$clientKey]);
                unset($this->notifiedByKey[$clientKey]);
                ++$removed;
            } else {
                $this->timestampsByKey[$clientKey] = $recent;
            }
        }

        foreach ($this->lockoutUntilByKey as $clientKey => $lockoutUntil) {
            if ($now >= $lockoutUntil && !isset($this->timestampsByKey[$clientKey])) {
                unset($this->lockoutUntilByKey[$clientKey]);
                unset($this->notifiedByKey[$clientKey]);
                ++$removed;
            }
        }

        return $removed;
    }

    public function markNotified(string $clientKey): void
    {
        $this->notifiedByKey[$clientKey] = true;
    }

    public function isNotified(string $clientKey): bool
    {
        return isset($this->notifiedByKey[$clientKey]);
    }

    public function clearNotifiedFor(string $clientKey): void
    {
        unset($this->notifiedByKey[$clientKey]);
    }
}
