<?php

declare(strict_types=1);

namespace App\Domain\IRC\Event;

use App\Domain\IRC\Connection\ConnectionInterface;

/**
 * Dispatched by the protocol handler just BEFORE it sends its own EOS/ENDBURST,
 * once the remote server's burst is fully received.
 *
 * Listeners with high priority (> 0) can use this event to introduce
 * service pseudo-clients (UID lines) before our EOS is sent to the IRCd.
 */
final readonly class NetworkBurstCompleteEvent
{
    public function __construct(
        public readonly ConnectionInterface $connection,
        public readonly string $serverSid,
    ) {
    }
}
