<?php

declare(strict_types=1);

namespace App\NickServ\Adapter\Out\InMemory;

use App\NickServ\Application\Port\In\IdentifiedSessionQuery;
use App\NickServ\Application\Port\Out\IdentifiedSessionTracker;
use Psr\Log\LoggerInterface;

use function array_key_first;
use function asort;
use function count;
use function strtolower;

/**
 * In-memory registry that tracks which registered nickname each connected
 * user (identified by their UID) has authenticated as.
 *
 * Populated by IdentifyCommand and RegisterCommand on successful auth.
 * Consumed by NickProtectionSubscriber when a user disconnects (QUIT) so
 * that the correct registered account is updated even if the user had
 * changed to a different nick (e.g. 'david') before quitting.
 */
final class IdentifiedSessionRegistry implements IdentifiedSessionQuery, IdentifiedSessionTracker
{
    /** @var array<string, string> uid → registered nick */
    private array $sessions;

    /** @var array<string, array<string, int>> lowercase nick → UIDs ordered by session registration */
    private array $uidsByNick = [];

    /** @var array<string, int> */
    private array $sessionOrder = [];

    private int $nextOrder = 0;

    public function __construct(
        private readonly ?LoggerInterface $logger = null,
    ) {
        $this->sessions = [];
    }

    public function register(string $uid, string $registeredNick): void
    {
        if (isset($this->sessions[$uid])) {
            $this->removeFromNickIndex($uid, $this->sessions[$uid]);
        } else {
            $this->sessionOrder[$uid] = $this->nextOrder++;
        }
        $this->sessions[$uid] = $registeredNick;
        $lowerNick = strtolower($registeredNick);
        $this->uidsByNick[$lowerNick][$uid] = $this->sessionOrder[$uid];
        asort($this->uidsByNick[$lowerNick]);
        $this->logger?->debug('IdentifiedSessionRegistry: registered session', ['uid' => $uid, 'nick' => $registeredNick, 'total' => count($this->sessions)]);
    }

    public function isIdentifiedAs(string $uid, string $registeredNickname): bool
    {
        $registered = $this->findNick($uid);

        return null !== $registered && strtolower($registered) === strtolower($registeredNickname);
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
        $this->logger?->debug('IdentifiedSessionRegistry: searching for nick', ['search' => $registeredNick, 'total' => count($this->sessions)]);

        return isset($this->uidsByNick[$lowerNick]) ? array_key_first($this->uidsByNick[$lowerNick]) : null;
    }

    /** Removes the UID entry (called on quit to free memory). */
    public function remove(string $uid): void
    {
        if (isset($this->sessions[$uid])) {
            $this->removeFromNickIndex($uid, $this->sessions[$uid]);
            unset($this->sessions[$uid], $this->sessionOrder[$uid]);
        }
    }

    /**
     * Removes sessions whose UID is no longer connected.
     * Returns the number of sessions removed. Used by maintenance to free memory.
     *
     * @param callable(string): bool $isConnected
     */
    public function pruneDisconnected(callable $isConnected): int
    {
        $removed = 0;

        foreach ($this->sessions as $uid => $nick) {
            if (!$isConnected($uid)) {
                $this->remove($uid);
                ++$removed;
            }
        }

        return $removed;
    }

    private function removeFromNickIndex(string $uid, string $nick): void
    {
        $lowerNick = strtolower($nick);
        unset($this->uidsByNick[$lowerNick][$uid]);
        if ([] === $this->uidsByNick[$lowerNick]) {
            unset($this->uidsByNick[$lowerNick]);
        }
    }
}
