<?php

declare(strict_types=1);

namespace App\NickServ\Application\Service;

use App\NickServ\Application\Port\In\NickVhostDisplayResolver;

/**
 * Builds the display vhost sent to the IRCd (user part + optional suffix).
 * E.g. stored "mi-vhost" with suffix "virtual" → "mi-vhost.virtual".
 */
final readonly class VhostDisplayResolver implements NickVhostDisplayResolver
{
    public function __construct(
        private string $vhostSuffix = '',
    ) {}

    /**
     * Returns the vhost string to send to the IRCd / show to the user.
     * Empty string if stored is null or empty.
     */
    public function getDisplayVhost(?string $storedVhost): string
    {
        if (null === $storedVhost || '' === $storedVhost) {
            return '';
        }

        $suffix = trim($this->vhostSuffix);
        if ('' === $suffix) {
            return $storedVhost;
        }

        return $storedVhost . '.' . $suffix;
    }
}
