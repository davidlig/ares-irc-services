<?php

declare(strict_types=1);

namespace App\Irc\Application\PublishedEvent;

/** Requests lightweight channel policy reconciliation after an existing-channel join. */
final readonly class ExistingChannelJoinObservedEvent
{
    public function __construct(public string $channelName, public bool $channelSetupApplicable) {}
}
