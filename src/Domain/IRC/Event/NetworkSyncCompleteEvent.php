<?php

declare(strict_types=1);

namespace App\Domain\IRC\Event;

use App\Domain\IRC\Connection\ConnectionInterface;

/**
 * Dispatched by the protocol handler AFTER it has sent its own EOS/ENDBURST
 * to the remote server. The link is fully synced; use this for actions that
 * must run after sync (e.g. ChanServ rejoining registered channels).
 */
final readonly class NetworkSyncCompleteEvent
{
    public function __construct(
        public readonly ConnectionInterface $connection,
        public readonly string $serverSid,
    ) {
    }
}
