<?php

declare(strict_types=1);

namespace App\Application\NickServ;

use DateTimeImmutable;

/**
 * In-memory store for email verification tokens issued during REGISTER.
 *
 * Maps nickname (lowercase) to a { token, expiresAt } pair.
 * Populated by RegisterCommand and consumed (then cleared) by VerifyCommand.
 * ResendCommand reads the stored email from RegisteredNick and generates
 * a new token, replacing any existing entry.
 *
 * Also tracks last RESEND timestamp per nick for throttling (minimum interval
 * between resend requests).
 *
 * This registry lives in the same process as the IRC loop, so tokens survive
 * as long as the service is running. Expired entries are either consumed by
 * VERIFY (which rejects them) or cleaned up by PurgeExpiredPendingTask.
 */
final class PendingVerificationRegistry
{
    /** @var array<string, array{token: string, expiresAt: DateTimeImmutable}> */
    private array $entries;

    /** @var array<string, DateTimeImmutable> */
    private array $lastResendAt;

    public function __construct()
    {
        $this->entries = [];
        $this->lastResendAt = [];
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

    public function has(string $nickname): bool
    {
        return isset($this->entries[strtolower($nickname)]);
    }

    public function remove(string $nickname): void
    {
        unset($this->entries[strtolower($nickname)]);
    }

    /**
     * Returns the last time a RESEND was successfully sent for this nick, or null.
     */
    public function getLastResendAt(string $nickname): ?DateTimeImmutable
    {
        return $this->lastResendAt[strtolower($nickname)] ?? null;
    }

    /**
     * Records that a RESEND was just sent for this nick (for throttling).
     */
    public function recordResend(string $nickname): void
    {
        $this->lastResendAt[strtolower($nickname)] = new DateTimeImmutable();
    }

    /**
     * Removes expired verification entries and lastResendAt entries older than maxAgeSecondsForResend.
     * Returns the total number of entries removed. Used by maintenance to free memory.
     */
    public function pruneExpired(int $maxAgeSecondsForResend = 86400): int
    {
        $now = new DateTimeImmutable();
        $resendCutoff = $now->modify(sprintf('-%d seconds', $maxAgeSecondsForResend));
        $removed = 0;

        foreach ($this->entries as $key => $entry) {
            if ($entry['expiresAt'] < $now) {
                unset($this->entries[$key]);
                ++$removed;
            }
        }

        foreach ($this->lastResendAt as $key => $at) {
            if ($at < $resendCutoff) {
                unset($this->lastResendAt[$key]);
                ++$removed;
            }
        }

        return $removed;
    }
}
