<?php

declare(strict_types=1);

namespace App\Irc\Application\PublishedEvent;

/** Published before the users of a delinked server (and its downstream servers) are quit, so listeners can batch the per-channel departures that follow. */
final readonly class ServerDelinkStartedEvent
{
    public function __construct(public string $serverSid) {}
}
