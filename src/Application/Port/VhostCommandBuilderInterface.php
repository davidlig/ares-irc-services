<?php

declare(strict_types=1);

namespace App\Application\Port;

/**
 * Port implemented per IRCd protocol: build the raw server line(s) to set or clear a user's vhost.
 * Services use this via the notifier; the bot delegates to the builder for the current protocol.
 */
interface VhostCommandBuilderInterface
{
    public function getSetVhostLine(string $serverSid, string $targetUid, string $vhost): string;

    /**
     * @return string[] One or more raw server lines to clear a user's vhost.
     *                  InspIRCd returns two lines: ENCAP CHGHOST (restore real host) + MODE +x (activate cloak).
     *                  UnrealIRCd returns one line: SVS2MODE -t.
     */
    public function getClearVhostLines(string $serverSid, string $targetUid, string $realHost): array;
}
