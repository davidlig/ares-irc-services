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

    /**
     * Returns a human-readable description of the client's identification for debug logging.
     * Decodes the base64 IP to a readable address; falls back to cloaked host or hostname.
     */
    public function getClientDescription(SenderView $user): string
    {
        if ('' !== $user->ipBase64 && '*' !== $user->ipBase64) {
            $binary = base64_decode($user->ipBase64, strict: true);

            if (false !== $binary) {
                $ip = inet_ntop($binary);

                if (false !== $ip) {
                    return $ip;
                }
            }

            return $user->ipBase64;
        }

        if ('' !== $user->cloakedHost) {
            return $user->cloakedHost;
        }

        if ('' !== $user->hostname) {
            return $user->hostname;
        }

        return $user->uid;
    }
}
