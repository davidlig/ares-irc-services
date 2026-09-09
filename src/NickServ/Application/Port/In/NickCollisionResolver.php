<?php

declare(strict_types=1);

namespace App\NickServ\Application\Port\In;

/** Public boundary for releasing a nickname held by an online user. */
interface NickCollisionResolver
{
    public function forceGuestNick(string $uid, ?string $guestNick = null, string $reason = 'enforcement'): void;
}
