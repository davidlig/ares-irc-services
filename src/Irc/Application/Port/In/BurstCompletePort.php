<?php

declare(strict_types=1);

namespace App\Irc\Application\Port\In;

/**
 * Port implemented by Core: indicates whether the IRC link has completed the initial burst.
 *
 * Services use this to decide when network state is fully synced (e.g. before enforcing MLOCK)
 * without depending on Application\IRC or BurstCompleteRegistry.
 */
interface BurstCompletePort
{
    public function isComplete(): bool;
}
