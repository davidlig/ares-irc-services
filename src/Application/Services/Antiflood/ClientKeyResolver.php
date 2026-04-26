<?php

declare(strict_types=1);

namespace App\Application\Services\Antiflood;

use App\Application\Port\SenderView;

/**
 * Resolves a stable client key for antiflood throttling.
 * Prefer IP → cloaked host → hostname so limits persist across reconnects; fall back to UID.
 */
final readonly class ClientKeyResolver
{
    public function getClientKey(SenderView $user): string
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

        return 'uid:' . $user->uid;
    }
}
