<?php

declare(strict_types=1);

namespace App\Application\NickServ;

/**
 * In-memory store for pending email change confirmations.
 *
 * When a user runs SET EMAIL <new_email>, a token is sent to their current
 * email address. This registry maps nick (lowercase) to { newEmail, token,
 * expiresAt }. The user confirms by running SET EMAIL <new_email> <token>.
 *
 * Token is sent to the CURRENT email so only the legitimate owner can confirm.
 */
final class PendingEmailChangeRegistry
{
    private const TTL_SECONDS = 3600;

    /** @var array<string, array{newEmail: string, token: string, expiresAt: \DateTimeImmutable}> */
    private array $entries = [];

    public function store(string $nickname, string $newEmail, string $token): void
    {
        $this->entries[strtolower($nickname)] = [
            'newEmail'  => $newEmail,
            'token'     => $token,
            'expiresAt' => new \DateTimeImmutable(sprintf('+%d seconds', self::TTL_SECONDS)),
        ];
    }

    /**
     * Validates and consumes the token for this nick and new email.
     * Returns true if valid and not expired; removes the entry. False otherwise.
     */
    public function consume(string $nickname, string $newEmail, string $token): bool
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

        if (strcasecmp($entry['newEmail'], $newEmail) !== 0 || !hash_equals($entry['token'], $token)) {
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
