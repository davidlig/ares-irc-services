<?php

declare(strict_types=1);

namespace App\Application\NickServ;

/**
 * Tracks UIDs for which services sent an SVSNICK to restore a registered nick.
 * Used by NickProtectionService to suppress false protection triggers.
 */
interface PendingNickRestoreRegistryInterface
{
    public function mark(string $uid): void;

    /**
     * Check if UID is pending without removing it.
     */
    public function peek(string $uid): bool;

    /**
     * Returns true and removes the entry if the UID was pending.
     */
    public function consume(string $uid): bool;
}
