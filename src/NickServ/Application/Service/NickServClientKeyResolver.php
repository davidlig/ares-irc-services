<?php

declare(strict_types=1);

namespace App\NickServ\Application\Service;

use App\NickServ\Application\Model\NetworkUser;

/**
 * Resolves a stable client key for NickServ throttling/lockout (REGISTER, IDENTIFY).
 * Prefer IP → cloaked host → hostname so limits persist across reconnects; fall back to UID.
 */
final readonly class NickServClientKeyResolver
{
    public function getClientKey(NetworkUser $user): string
    {
        $key = match (true) {
            '' !== $user->ipBase64 && '*' !== $user->ipBase64 => 'ip:' . $user->ipBase64,
            '' !== $user->cloakedHost => 'cloak:' . $user->cloakedHost,
            '' !== $user->hostname => 'host:' . $user->hostname,
            default => 'uid:' . $user->uid,
        };

        return $key;
    }
}
