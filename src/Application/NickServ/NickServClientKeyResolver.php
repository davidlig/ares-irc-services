<?php

declare(strict_types=1);

namespace App\Application\NickServ;

use App\Domain\IRC\Network\NetworkUser;

/**
 * Resolves a stable client key for NickServ throttling/lockout (REGISTER, IDENTIFY).
 * Prefer IP → cloaked host → hostname so limits persist across reconnects; fall back to UID.
 */
final readonly class NickServClientKeyResolver
{
    public function getClientKey(NetworkUser $user): string
    {
        if ('' !== $user->ipBase64 && '*' !== $user->ipBase64) {
            return 'ip:' . $user->ipBase64;
        }

        if ('' !== $user->cloakedHost) {
            return 'cloak:' . $user->cloakedHost;
        }

        if ('' !== $user->hostname) {
            return 'host:' . $user->hostname;
        }

        return 'uid:' . $user->uid->value;
    }
}
