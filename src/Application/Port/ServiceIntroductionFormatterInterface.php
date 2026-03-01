<?php

declare(strict_types=1);

namespace App\Application\Port;

/**
 * Port implemented per IRCd protocol: build the raw server line(s) to introduce
 * a service pseudo-client (e.g. NickServ) after burst complete. Services use
 * this via the bot; the bot delegates to the formatter for the current protocol.
 */
interface ServiceIntroductionFormatterInterface
{
    /**
     * Returns the raw line(s) to send to introduce the service to the network.
     * Caller must send the returned line(s) over the connection (e.g. one writeLine per line).
     *
     * @return string One or more raw lines (e.g. single UID for Unreal, single UID for InspIRCd)
     */
    public function formatIntroduction(
        string $serverSid,
        string $nick,
        string $ident,
        string $host,
        string $uid,
        string $realname,
    ): string;
}
