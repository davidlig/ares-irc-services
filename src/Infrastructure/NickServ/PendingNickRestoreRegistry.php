<?php

declare(strict_types=1);

namespace App\Infrastructure\NickServ;

use App\Application\NickServ\PendingNickRestoreRegistryInterface;
use App\Domain\IRC\SkipIdentifiedModeStripRegistryInterface;

/**
 * Tracks UIDs for which services sent an SVSNICK to restore a registered nick
 * (e.g. during the IDENTIFY flow). Uses a counter so multiple SVSNICKs per UID
 * (protection → Guest + restore → original) are tracked independently.
 *
 * Used by NickProtectionService to suppress false protection triggers when
 * the NICK echo arrives before the UMODE2 +r.
 *
 * Also implements SkipIdentifiedModeStripRegistryInterface so the protocol
 * layer can peek (without consuming) to skip stripping +r on the NICK echo.
 */
final class PendingNickRestoreRegistry implements PendingNickRestoreRegistryInterface, SkipIdentifiedModeStripRegistryInterface
{
    /** @var array<string, int> */
    private array $pending = [];

    public function mark(string $uid): void
    {
        $this->pending[$uid] = ($this->pending[$uid] ?? 0) + 1;
    }

    public function peek(string $uid): bool
    {
        return ($this->pending[$uid] ?? 0) > 0;
    }

    /**
     * Decrements the counter and returns true if the UID had pending tokens.
     * Idempotent: safe to call even if the UID was never marked.
     */
    public function consume(string $uid): bool
    {
        if (($this->pending[$uid] ?? 0) > 0) {
            --$this->pending[$uid];
            if (0 === $this->pending[$uid]) {
                unset($this->pending[$uid]);
            }

            return true;
        }

        return false;
    }
}
