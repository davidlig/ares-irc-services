<?php

declare(strict_types=1);

namespace App\Irc\Application\PublishedEvent;

/**
 * The remote server's burst has ended on the current link and end-of-sync work is about to start.
 *
 * Published before any other end-of-sync listener runs, so it is delivered even when one of them
 * throws and NetworkSynchronizationCompletedEvent is never published.
 */
final readonly class RemoteBurstEndedEvent
{
    public function __construct(public string $serverSid) {}
}
