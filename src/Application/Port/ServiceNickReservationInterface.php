<?php

declare(strict_types=1);

namespace App\Application\Port;

use App\Domain\IRC\Connection\ConnectionInterface;

/**
 * Reserve service nicknames before pseudo-clients are introduced.
 *
 * Implementations send IRCd-specific commands (SQLINE for UnrealIRCd,
 * QLINE for InspIRCd) to prevent regular users from taking service
 * nicknames. U-lined servers can still introduce the reserved nicks.
 */
interface ServiceNickReservationInterface
{
    /**
     * Reserve a nickname so regular users cannot use it.
     *
     * @param ConnectionInterface $connection The active IRC connection
     * @param string              $serverSid  The SID of the services server
     * @param string              $nick       The nickname to reserve (e.g., "NickServ")
     * @param string              $reason     The reason shown to users who try to use it
     */
    public function reserveNick(ConnectionInterface $connection, string $serverSid, string $nick, string $reason): void;

    /**
     * Reserve a nickname for a limited duration.
     *
     * Used for temporary pseudo-clients that need a nick reservation that expires.
     *
     * @param ConnectionInterface $connection      The active IRC connection
     * @param string              $serverSid       The SID of the services server
     * @param string              $nick            The nickname to reserve
     * @param int                 $durationSeconds Duration in seconds (0 = permanent)
     * @param string              $reason          The reason shown to users who try to use it
     */
    public function reserveNickWithDuration(ConnectionInterface $connection, string $serverSid, string $nick, int $durationSeconds, string $reason): void;
}
