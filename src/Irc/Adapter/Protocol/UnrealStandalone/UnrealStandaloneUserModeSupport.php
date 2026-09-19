<?php

declare(strict_types=1);

namespace App\Irc\Adapter\Protocol\UnrealStandalone;

use App\Irc\Application\Port\In\UserModeSupportInterface;

/**
 * UnrealIRCd IRCOp-only user modes.
 *
 * @see https://www.unrealircd.org/docs/User_modes
 */
final readonly class UnrealStandaloneUserModeSupport implements UserModeSupportInterface
{
    /**
     * IRCOp-only user modes that can be set via services.
     * Excludes: 'r' (registered), 'S' (services bot), 't' (vhost).
     */
    private const array IRCOP_USER_MODES = ['H', 'o', 'q', 's', 'W'];

    public function getIrcOpUserModes(): array
    {
        return self::IRCOP_USER_MODES;
    }

    public function buildModeParams(string $sign, array $modes): array
    {
        $modeStr = $sign . implode('', $modes);

        return [$modeStr, []];
    }
}
