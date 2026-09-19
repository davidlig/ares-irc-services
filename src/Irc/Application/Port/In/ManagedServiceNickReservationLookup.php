<?php

declare(strict_types=1);

namespace App\Irc\Application\Port\In;

/** Optional capability for protocols that can identify existing Ares-owned reservations. */
interface ManagedServiceNickReservationLookup
{
    /** @return list<string> */
    public function findManagedServiceNicks(string $reason): array;
}
