<?php

declare(strict_types=1);

namespace App\Application\NickServ;

/**
 * In-memory registry that tracks which registered nickname each connected
 * user (identified by their UID) has authenticated as.
 *
 * Populated by IdentifyCommand and RegisterCommand on successful auth.
 * Consumed by NickProtectionSubscriber when a user disconnects (QUIT) so
 * that the correct registered account is updated even if the user had
 * changed to a different nick (e.g. 'david') before quitting.
 */
final class IdentifiedSessionRegistry
{
    /** @var array<string, string> uid → registered nick */
    private array $sessions;

    public function __construct()
    {
        $this->sessions = [];
    }

    public function register(string $uid, string $registeredNick): void
    {
        $this->sessions[$uid] = $registeredNick;
    }

    /** Returns the registered nick for the UID, or null if not tracked. */
    public function findNick(string $uid): ?string
    {
        return $this->sessions[$uid] ?? null;
    }

    /** Removes the UID entry (called on quit to free memory). */
    public function remove(string $uid): void
    {
        unset($this->sessions[$uid]);
    }

    /**
     * Removes sessions whose UID is not in the given list (e.g. users no longer connected).
     * Returns the number of sessions removed. Used by maintenance to free memory.
     *
     * @param array<string> $validUids List of UID strings that are still valid (e.g. currently connected).
     */
    public function pruneSessionsNotIn(array $validUids): int
    {
        $validSet = array_fill_keys($validUids, true);
        $removed = 0;

        foreach (array_keys($this->sessions) as $uid) {
            if (!isset($validSet[$uid])) {
                unset($this->sessions[$uid]);
                ++$removed;
            }
        }

        return $removed;
    }
}
