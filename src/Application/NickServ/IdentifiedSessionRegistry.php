<?php

declare(strict_types=1);

namespace App\Application\NickServ;

use Psr\Log\LoggerInterface;

use function count;

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

    public function __construct(
        private readonly ?LoggerInterface $logger = null,
    ) {
        $this->sessions = [];
    }

    public function register(string $uid, string $registeredNick): void
    {
        $this->sessions[$uid] = $registeredNick;
        $this->logger?->debug('IdentifiedSessionRegistry: registered session', ['uid' => $uid, 'nick' => $registeredNick, 'total' => count($this->sessions)]);
    }

    /** Returns the registered nick for the UID, or null if not tracked. */
    public function findNick(string $uid): ?string
    {
        return $this->sessions[$uid] ?? null;
    }

    /** Returns the UID for a registered nick, or null if not identified. */
    public function findUidByNick(string $registeredNick): ?string
    {
        $lowerNick = strtolower($registeredNick);
        $this->logger?->debug('IdentifiedSessionRegistry: searching for nick', ['search' => $registeredNick, 'sessions' => $this->sessions]);
        foreach ($this->sessions as $uid => $nick) {
            if (strtolower($nick) === $lowerNick) {
                return $uid;
            }
        }

        return null;
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
