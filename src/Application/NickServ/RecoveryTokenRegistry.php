<?php

declare(strict_types=1);

namespace App\Application\NickServ;

use DateTimeImmutable;

use function sprintf;

/**
 * In-memory store for password recovery tokens (RECOVER command).
 *
 * Maps nickname (lowercase) to a { token, expiresAt } pair.
 * Populated when user runs RECOVER <nickname>; consumed when they run RECOVER <nickname> <token>.
 *
 * Also tracks last recovery email sent per nick for throttling (minimum interval
 * between recovery requests).
 */
final class RecoveryTokenRegistry
{
    /** @var array<string, array{token: string, expiresAt: DateTimeImmutable}> */
    private array $entries;

    /** @var array<string, DateTimeImmutable> */
    private array $lastRecoverAt;

    public function __construct()
    {
        $this->entries = [];
        $this->lastRecoverAt = [];
    }

    public function store(string $nickname, string $token, DateTimeImmutable $expiresAt): void
    {
        $this->entries[strtolower($nickname)] = [
            'token' => $token,
            'expiresAt' => $expiresAt,
        ];
    }

    /**
     * Validates and consumes a token.
     * Returns true if the token matches and has not expired, then removes the entry.
     * Returns false if the token is missing, mismatched, or expired.
     */
    public function consume(string $nickname, string $token): bool
    {
        $key = strtolower($nickname);
        $entry = $this->entries[$key] ?? null;

        if (null === $entry) {
            return false;
        }

        if ($entry['expiresAt'] < new DateTimeImmutable()) {
            unset($this->entries[$key]);

            return false;
        }

        if (!hash_equals($entry['token'], $token)) {
            return false;
        }

        unset($this->entries[$key]);

        return true;
    }

    /**
     * Returns the last time a recovery email was successfully sent for this nick, or null.
     */
    public function getLastRecoverAt(string $nickname): ?DateTimeImmutable
    {
        return $this->lastRecoverAt[strtolower($nickname)] ?? null;
    }

    /**
     * Records that a recovery email was just sent for this nick (for throttling).
     */
    public function recordRecover(string $nickname): void
    {
        $this->lastRecoverAt[strtolower($nickname)] = new DateTimeImmutable();
    }

    /**
     * Removes expired recovery entries and lastRecoverAt entries older than maxAgeSecondsForRecover.
     * Returns the total number of entries removed.
     */
    public function pruneExpired(int $maxAgeSecondsForRecover = 86400): int
    {
        $now = new DateTimeImmutable();
        $recoverCutoff = $now->modify(sprintf('-%d seconds', $maxAgeSecondsForRecover));
        $removed = 0;

        foreach ($this->entries as $key => $entry) {
            if ($entry['expiresAt'] < $now) {
                unset($this->entries[$key]);
                ++$removed;
            }
        }

        foreach ($this->lastRecoverAt as $key => $at) {
            if ($at < $recoverCutoff) {
                unset($this->lastRecoverAt[$key]);
                ++$removed;
            }
        }

        return $removed;
    }
}
