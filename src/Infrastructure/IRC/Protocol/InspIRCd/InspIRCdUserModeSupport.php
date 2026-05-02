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

    private const string SNOMASK_DEFAULT = '+kcfj';

    /**
     * Mode letters that require a parameter on set.
     * 's' (snomask) is PARAM_SETONLY in InspIRCd — needs param on set, not on remove.
     */
    private const array PARAM_SET_MODE_LETTERS = ['s'];

    public function getIrcOpUserModes(): array
    {
        return self::IRCOP_USER_MODES;
    }

    public function buildModeParams(string $sign, array $modes): array
    {
        $modeStr = $sign;
        $params = [];
        $isAdd = '+' === $sign;

        foreach ($modes as $mode) {
            $modeStr .= $mode;

            if ($isAdd && 's' === $mode) {
                $params[] = self::SNOMASK_DEFAULT;
            }
        }

        return [$modeStr, $params];
    }
}
