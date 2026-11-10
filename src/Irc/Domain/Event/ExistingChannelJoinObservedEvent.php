<?php

declare(strict_types=1);

namespace App\Irc\Domain\Event;

use App\Irc\Domain\Network\Channel;

/** Carries existing-channel join state without requesting full rank synchronization. */
final readonly class ExistingChannelJoinObservedEvent
{
    public function __construct(public Channel $channel, public bool $channelSetupApplicable) {}
}
