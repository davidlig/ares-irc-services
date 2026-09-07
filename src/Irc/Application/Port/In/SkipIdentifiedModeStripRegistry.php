<?php

declare(strict_types=1);

namespace App\Irc\Application\Port\In;

/** Allows IRC state synchronization to preserve +r for a service-originated nick restore. */
interface SkipIdentifiedModeStripRegistry
{
    public function peek(string $uid): bool;
}
