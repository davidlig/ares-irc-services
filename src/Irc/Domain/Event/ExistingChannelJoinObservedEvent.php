<?php

declare(strict_types=1);

namespace App\Irc\Domain\Event;

use App\Irc\Domain\Network\Channel;

/**
 * Carries existing-channel join state without requesting full rank synchronization.
 * fromBurstingServer is true when the join was sent by a server still bursting
 * (initial link or a server reintroduced after a netsplit), false for ordinary joins.
 */
final readonly class ExistingChannelJoinObservedEvent
{
    public function __construct(
        public Channel $channel,
        public bool $channelSetupApplicable,
        public bool $fromBurstingServer = false,
    ) {}
}
