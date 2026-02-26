<?php

declare(strict_types=1);

namespace App\Infrastructure\NickServ;

use App\Application\NickServ\PendingNickRestoreRegistryInterface;
use App\Domain\IRC\SkipIdentifiedModeStripRegistryInterface;

/**
 * Tracks UIDs for which services sent an SVSNICK to restore a registered nick
 * (e.g. during the IDENTIFY flow). Used by NickProtectionService to suppress
 * false protection triggers when the NICK echo arrives before the UMODE2 +r.
 *
 * Also implements SkipIdentifiedModeStripRegistryInterface so the protocol
 * layer can peek (without consuming) to skip stripping +r on the NICK echo.
 */
final class PendingNickRestoreRegistry implements PendingNickRestoreRegistryInterface, SkipIdentifiedModeStripRegistryInterface
{
    /** @var array<string, bool> */
    private array $pending = [];

    public function mark(string $uid): void
    {
        $this->pending[$uid] = true;
    }

    public function peek(string $uid): bool
    {
        return isset($this->pending[$uid]);
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
