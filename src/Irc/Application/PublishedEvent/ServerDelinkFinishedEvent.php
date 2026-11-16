<?php

declare(strict_types=1);

namespace App\Irc\Application\PublishedEvent;

/** Published after every user of a delinked server has been quit, including when a quit listener failed. */
final readonly class ServerDelinkFinishedEvent
{
    public function __construct(public string $serverSid) {}
}
