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
        $result = match (true) {
            '' !== $user->ipBase64 && '*' !== $user->ipBase64 => 'ip:' . $user->ipBase64,
            '' !== $user->cloakedHost => 'cloak:' . $user->cloakedHost,
            '' !== $user->hostname => 'host:' . $user->hostname,
            default => 'uid:' . $user->uid,
        };

        return $result;
    }

    /**
     * Returns a human-readable description of the client's identification for debug logging.
     * Decodes the base64 IP to a readable address; falls back to cloaked host or hostname.
     */
    public function getClientDescription(SenderView $user): string
    {
        if ('' !== $user->ipBase64 && '*' !== $user->ipBase64) {
            $binary = base64_decode($user->ipBase64, strict: true);
            $ip = false !== $binary ? inet_ntop($binary) : false;

            return false !== $ip ? $ip : $user->ipBase64;
        }

        $result = match (true) {
            '' !== $user->cloakedHost => $user->cloakedHost,
            '' !== $user->hostname => $user->hostname,
            default => $user->uid,
        };

        return $result;
    }
}
