<?php

declare(strict_types=1);

namespace App\Infrastructure\NickServ;

use App\Application\NickServ\PendingNickRestoreRegistryInterface;

/**
 * Tracks UIDs for which services sent an SVSNICK to restore a registered nick
 * (e.g. during the IDENTIFY flow). Used by NickProtectionService to suppress
 * false protection triggers when the NICK echo arrives before the UMODE2 +r.
 */
final class PendingNickRestoreRegistry implements PendingNickRestoreRegistryInterface
{
    /** @var array<string, bool> */
    private array $pending;

    public function __construct()
    {
        $this->pending = [];
    }

    public function mark(string $uid): void
    {
        $this->pending[$uid] = true;
    }

    /**
     * Returns true and removes the entry if the UID was pending.
     * Idempotent: safe to call even if the UID was never marked.
     */
    public function consume(string $uid): bool
    {
        if (isset($this->pending[$uid])) {
            unset($this->pending[$uid]);

            return true;
        }

        return false;
    }
}
