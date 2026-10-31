<?php

declare(strict_types=1);

namespace App\Irc\Adapter\In\Event;

use App\Irc\Adapter\Network\ApplyOutgoingChannelKickApplicatorInterface;
use App\Irc\Application\Port\In\ApplyOutgoingChannelKickPort;

/**
 * Core implements ApplyOutgoingChannelKickPort: applies KICK sent by services
 * to the channel state so ChannelLookup and member state stay in sync.
 */
final readonly class CoreApplyOutgoingChannelKickAdapter implements ApplyOutgoingChannelKickPort
{
    public function __construct(
        private ApplyOutgoingChannelKickApplicatorInterface $applicator,
    ) {}

    public function applyOutgoingKick(string $channelName, string $targetUid, string $reason): void
    {
        $this->applicator->applyOutgoingKick($channelName, $targetUid, $reason);
    }
}
