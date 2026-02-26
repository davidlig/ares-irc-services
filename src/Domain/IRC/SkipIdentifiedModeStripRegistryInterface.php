<?php

declare(strict_types=1);

namespace App\Domain\IRC;

/**
 * Allows the protocol layer to check whether the identified (+r) mode strip
 * should be skipped for a given UID on the next nick change, without consuming
 * the flag. Implementations are used when services originate a nick change
 * (e.g. restore registered nick) so that the echo of that change does not
 * strip +r from the local user state.
 */
interface SkipIdentifiedModeStripRegistryInterface
{
    /**
     * Returns true if the identified (+r) mode strip should be skipped for
     * this UID on the next nick change. Does not modify state (no consume).
     */
    public function peek(string $uid): bool;
}
