<?php

declare(strict_types=1);

namespace App\NickServ\Application\Port\In;

/** Public read boundary for currently identified NickServ sessions. */
interface IdentifiedSessionQuery
{
    public function findUidByNick(string $registeredNick): ?string;
}
