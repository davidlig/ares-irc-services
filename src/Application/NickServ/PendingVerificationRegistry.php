<?php

declare(strict_types=1);

namespace App\Application\NickServ;

/**
 * In-memory store for email verification tokens issued during REGISTER.
 *
 * Maps nickname (lowercase) to a { token, expiresAt } pair.
 * Populated by RegisterCommand and consumed (then cleared) by VerifyCommand.
 * ResendCommand reads the stored email from RegisteredNick and generates
 * a new token, replacing any existing entry.
 *
 * This registry lives in the same process as the IRC loop, so tokens survive
 * as long as the service is running. Expired entries are either consumed by
 * VERIFY (which rejects them) or cleaned up by PurgeExpiredPendingTask.
 */
final class PendingVerificationRegistry
{
    /** @var array<string, array{token: string, expiresAt: \DateTimeImmutable}> */
    private array $entries = [];

    public function store(string $nickname, string $token, \DateTimeImmutable $expiresAt): void
    {
        $this->entries[strtolower($nickname)] = [
            'token'     => $token,
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
        $key   = strtolower($nickname);
        $entry = $this->entries[$key] ?? null;

        if ($entry === null) {
            return false;
        }

        if ($entry['expiresAt'] < new \DateTimeImmutable()) {
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
}
