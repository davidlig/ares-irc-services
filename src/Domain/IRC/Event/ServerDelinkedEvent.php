<?php

declare(strict_types=1);

namespace App\Domain\IRC\Event;

/**
 * Dispatched when a server is removed from the network (SQUIT).
 * Subscribers should treat all users on that server as disconnected and
 * clean state (repos, IdentifiedSessionRegistry) the same as for QUIT.
 */
readonly class ServerDelinkedEvent
{
    public function __construct(
        /** SID of the server that was delinked (e.g. "002"). */
        public readonly string $serverSid,
        /** Optional reason from the SQUIT message. */
        public readonly string $reason = '',
    ) {
    }
}
