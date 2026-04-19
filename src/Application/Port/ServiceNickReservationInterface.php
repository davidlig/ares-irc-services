<?php

declare(strict_types=1);

namespace App\Application\Port;

/**
 * Reserve service nicknames before pseudo-clients are introduced.
 *
 * Implementations send IRCd-specific commands (SQLINE for UnrealIRCd,
 * ADDLINE Q for InspIRCd v4) to prevent regular users from taking service
 * nicknames. U-lined servers can still introduce the reserved nicks.
 */
interface ServiceNickReservationInterface
{
    /**
     * Reserve a nickname so regular users cannot use it.
     *
     * @param string $nick   The nickname to reserve (e.g., "NickServ")
     * @param string $reason The reason shown to users who try to use it
     */
    public function reserveNick(string $nick, string $reason): void;

    /**
     * Reserve a nickname for a limited duration.
     *
     * Used for temporary pseudo-clients that need a nick reservation that expires.
     *
     * @param string $nick            The nickname to reserve
     * @param int    $durationSeconds Duration in seconds (0 = permanent)
     * @param string $reason          The reason shown to users who try to use it
     */
    public function reserveNickWithDuration(string $nick, int $durationSeconds, string $reason): void;

    /**
     * Release a previously reserved nickname.
     *
     * Removes the SQLINE/ADDLINE reservation, allowing regular users to use the nick.
     *
     * @param string $nick The nickname to release
     */
    public function releaseNick(string $nick): void;
}
