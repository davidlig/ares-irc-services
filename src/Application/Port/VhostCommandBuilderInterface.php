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

    public function getClearVhostLine(string $serverSid, string $targetUid): string;
}
