<?php

declare(strict_types=1);

namespace App\NickServ\Application\Port\In;

/** Public boundary for releasing a nickname held by an online user. */
interface NickCollisionResolver
{
    /**
     * Forces the user to a guest nickname.
     *
     * @return string|null the guest nickname applied, or null when the UID is no longer online
     */
    public function forceGuestNick(string $uid, ?string $guestNick = null, string $reason = 'enforcement'): ?string;
}
