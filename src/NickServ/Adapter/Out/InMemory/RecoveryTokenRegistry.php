<?php

declare(strict_types=1);

namespace App\NickServ\Adapter\Out\InMemory;

use App\NickServ\Application\Port\Out\RecoveryTokenStore;
use DateTimeImmutable;
use OverflowException;

use function count;
use function sprintf;

/**
 * In-memory store for password recovery tokens (RECOVER command).
 *
 * Maps nickname (lowercase) to a { SHA-256 hash, expiresAt } pair.
 * Populated when user runs RECOVER <nickname>; consumed when they run RECOVER <nickname> <token>.
 *
 * Also tracks last recovery email sent per nick for throttling (minimum interval
 * between recovery requests).
 */
final class RecoveryTokenRegistry implements RecoveryTokenStore
{
    /** @var array<string, array{token: string, expiresAt: DateTimeImmutable}> */
    private array $entries;

    /** @var array<string, DateTimeImmutable> */
    private array $lastRecoverAt;

    public function __construct(private readonly int $maxEntries = 10000)
    {
        $this->entries = [];
        $this->lastRecoverAt = [];
    }

    public function store(string $nickname, string $token, DateTimeImmutable $expiresAt): void
    {
        if (!isset($this->entries[strtolower($nickname)]) && count($this->entries) >= $this->maxEntries) {
            throw new OverflowException('Recovery token capacity exhausted.');
        }
        $this->entries[strtolower($nickname)] = [
            'token' => hash('sha256', strtolower(trim($token))),
            'expiresAt' => $expiresAt,
        ];
    }

    /**
     * Validates and consumes a token.
     * Returns true if the token matches and has not expired, then removes the entry.
     * Returns false if the token is missing, mismatched, or expired.
     */
    public function consume(string $nickname, string $token, DateTimeImmutable $now): bool
    {
        $valid = $this->matches($nickname, $token, $now);
        if ($valid) {
            $this->removeIfMatches($nickname, $token);
        }

        return $valid;
    }

    public function matches(string $nickname, string $token, DateTimeImmutable $now): bool
    {
        $key = strtolower($nickname);
        $entry = $this->entries[$key] ?? null;
        if (null === $entry) {
            return false;
        }
        if ($entry['expiresAt'] <= $now) {
            unset($this->entries[$key]);

            return false;
        }

        return hash_equals($entry['token'], hash('sha256', strtolower(trim($token))));
    }

    public function removeIfMatches(string $nickname, string $token): void
    {
        $key = strtolower($nickname);
        if (isset($this->entries[$key]) && hash_equals($this->entries[$key]['token'], hash('sha256', strtolower(trim($token))))) {
            unset($this->entries[$key]);
        }
    }

    /**
     * Returns the last reserved recovery request time for this nick, or null.
     */
    public function getLastRecoverAt(string $nickname): ?DateTimeImmutable
    {
        return $this->lastRecoverAt[strtolower($nickname)] ?? null;
    }

    /**
     * Reserves a recovery request timestamp before attempting delivery.
     */
    public function recordRecover(string $nickname, DateTimeImmutable $now): void
    {
        if (!isset($this->lastRecoverAt[strtolower($nickname)]) && count($this->lastRecoverAt) >= $this->maxEntries) {
            throw new OverflowException('Recovery timestamp capacity exhausted.');
        }
        $this->lastRecoverAt[strtolower($nickname)] = $now;
    }

    /**
     * Removes expired recovery entries and lastRecoverAt entries older than maxAgeSecondsForRecover.
     * Returns the total number of entries removed.
     */
    public function pruneExpired(DateTimeImmutable $now, int $maxAgeSecondsForRecover = 86400): int
    {
        $recoverCutoff = $now->modify(sprintf('-%d seconds', $maxAgeSecondsForRecover));
        $removed = 0;

        foreach ($this->entries as $key => $entry) {
            if ($entry['expiresAt'] <= $now) {
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
