<?php

declare(strict_types=1);

namespace App\Irc\Domain\Event;

use function array_unique;
use function array_values;

/**
 * Dispatched when a server is removed from the network (SQUIT).
 * Subscribers should treat all users on that server (and any downstream servers)
 * as disconnected and clean state (repos, IdentifiedSessionRegistry) the same as for QUIT.
 */
final readonly class ServerDelinkedEvent
{
    /** @var list<string> */
    public array $serverSids;

    /**
     * @param string   $serverSid  SID of the server that was delinked (e.g. "002").
     * @param string   $reason     optional reason from the SQUIT message
     * @param string[] $serverSids all delinked server SIDs including descendants
     */
    public function __construct(
        public string $serverSid,
        public string $reason = '',
        array $serverSids = [],
    ) {
        $this->serverSids = [] !== $serverSids
            ? array_values(array_unique($serverSids))
            : ('' !== $serverSid ? [$serverSid] : []);
    }
}
