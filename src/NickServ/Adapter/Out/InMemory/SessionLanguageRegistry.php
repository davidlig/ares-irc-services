<?php

declare(strict_types=1);

namespace App\NickServ\Adapter\Out\InMemory;

use App\NickServ\Application\Port\Out\SessionLanguageTracker;

/**
 * In-memory registry that stores temporary language preferences for
 * unregistered/non-identified users. Cleared when the user disconnects.
 *
 * Populated by SetCommand when an unregistered user uses SET LANGUAGE.
 * Consumed by UserLanguageResolver to determine the response language.
 */
class SessionLanguageRegistry implements SessionLanguageTracker
{
    /** @var array<string, string> uid → language code */
    private array $sessions;

    public function __construct()
    {
        $this->sessions = [];
    }

    public function register(string $uid, string $language): void
    {
        $this->sessions[$uid] = $language;
    }

    public function find(string $uid): ?string
    {
        return $this->sessions[$uid] ?? null;
    }

    public function remove(string $uid): void
    {
        unset($this->sessions[$uid]);
    }

    /**
     * Removes sessions whose UID is no longer connected.
     * Returns the number of sessions removed.
     *
     * @param callable(string): bool $isConnected
     */
    public function pruneDisconnected(callable $isConnected): int
    {
        $removed = 0;

        foreach ($this->sessions as $uid => $language) {
            if (!$isConnected($uid)) {
                unset($this->sessions[$uid]);
                ++$removed;
            }
        }

        return $removed;
    }
}
