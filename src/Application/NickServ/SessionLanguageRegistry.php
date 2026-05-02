<?php

declare(strict_types=1);

namespace App\Application\NickServ;

/**
 * In-memory registry that stores temporary language preferences for
 * unregistered/non-identified users. Cleared when the user disconnects.
 *
 * Populated by SetCommand when an unregistered user uses SET LANGUAGE.
 * Consumed by UserLanguageResolver to determine the response language.
 */
class SessionLanguageRegistry
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
     * Removes sessions whose UID is not in the given list.
     * Returns the number of sessions removed.
     *
     * @param array<string> $validUids
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
