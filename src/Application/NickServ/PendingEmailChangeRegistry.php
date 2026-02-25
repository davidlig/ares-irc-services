<?php

declare(strict_types=1);

namespace App\Application\NickServ;

use DateTimeImmutable;

use function sprintf;

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
    private const int TTL_SECONDS = 3600;

    /** @var array<string, array{newEmail: string, token: string, expiresAt: DateTimeImmutable}> */
    private array $entries;

    public function __construct()
    {
        $this->entries = [];
    }

    public function store(string $nickname, string $newEmail, string $token): void
    {
        $this->entries[strtolower($nickname)] = [
            'newEmail' => $newEmail,
            'token' => $token,
            'expiresAt' => new DateTimeImmutable(sprintf('+%d seconds', self::TTL_SECONDS)),
        ];
    }

    /**
     * Validates and consumes the token for this nick and new email.
     * Returns true if valid and not expired; removes the entry. False otherwise.
     */
    public function consume(string $nickname, string $newEmail, string $token): bool
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

        if (0 !== strcasecmp($entry['newEmail'], $newEmail) || !hash_equals($entry['token'], $token)) {
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
     * Removes entries whose token has expired. Returns the number of entries removed.
     * Used by maintenance to free memory.
     */
    public function pruneExpired(): int
    {
        $now = new DateTimeImmutable();
        $removed = 0;

        foreach ($this->entries as $key => $entry) {
            if ($entry['expiresAt'] < $now) {
                unset($this->entries[$key]);
                ++$removed;
            }
        }

        return $removed;
    }
}
