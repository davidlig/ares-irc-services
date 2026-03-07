<?php

declare(strict_types=1);

namespace App\Domain\IRC\Event;

/**
 * Dispatched after MessageReceivedEvent and all its subscribers have run.
 * Use for "end of message" work (e.g. coalesced rank sync) so that multiple
 * domain events in the same IRC message result in a single sync per channel.
 */
final readonly class IrcMessageProcessedEvent
{
    public function __construct()
    {
    }
}
