<?php

declare(strict_types=1);

namespace App\Infrastructure\IRC\Protocol\InspIRCd;

use App\Application\Port\UserModeSupportInterface;

/**
 * InspIRCd IRCOp-only user modes.
 *
 * @see https://docs.inspircd.org/4/user-modes/
 */
final readonly class InspIRCdUserModeSupport implements UserModeSupportInterface
{
    /**
     * IRCOp-only user modes that can be set via services.
     * Excludes: 'o' (set by IRCd on /OPER), 'r' (registered), 'S' (services bot).
     */
    private const array IRCOP_USER_MODES = ['s', 'W'];

    public function getIrcOpUserModes(): array
    {
        return self::IRCOP_USER_MODES;
    }
}
