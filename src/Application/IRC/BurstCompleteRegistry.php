<?php

declare(strict_types=1);

namespace App\Application\IRC;

/**
 * Tracks whether the IRC link has completed the initial burst (EOS/ENDBURST).
 *
 * Maintenance cycles must not run until burst is complete, so the network
 * state is fully synced before any purge or cleanup tasks execute.
 */
final class BurstCompleteRegistry
{
    private bool $burstComplete = false;

    public function setBurstComplete(bool $complete): void
    {
        $this->burstComplete = $complete;
    }

    public function isBurstComplete(): bool
    {
        return $this->burstComplete;
    }
}
