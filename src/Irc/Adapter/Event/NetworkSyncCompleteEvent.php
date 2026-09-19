<?php

declare(strict_types=1);

namespace App\Irc\Adapter\Event;

use App\Irc\Adapter\Out\Connection\ConnectionInterface;

/**
 * Dispatched by the protocol handler once the direct peer's initial burst is
 * complete. The handler has already finished its protocol-specific readiness
 * work, so consumers may safely run post-sync actions such as channel rejoins.
 */
final readonly class NetworkSyncCompleteEvent
{
    public function __construct(
        public ConnectionInterface $connection,
        public string $serverSid,
    ) {}
}
