<?php

declare(strict_types=1);

namespace App\Irc\Adapter\Protocol\InspIRCd;

use App\Irc\Application\Port\In\UserModeSupportInterface;

/**
 * InspIRCd user mode support.
 *
 * IRCOp-only user modes (+s, +W, etc.) cannot be set by services in InspIRCd
 * without prior IRCd-side configuration (services server tag + oper type +
 * SVSOPER). Since this requires manual IRCd config, no modes are exposed.
 *
 * @see https://docs.inspircd.org/4/user-modes/
 */
final readonly class InspIRCdUserModeSupport implements UserModeSupportInterface
{
    public function getIrcOpUserModes(): array
    {
        return [];
    }

    public function buildModeParams(string $sign, array $modes): array
    {
        $modeStr = $sign . implode('', $modes);

        return [$modeStr, []];
    }
}
