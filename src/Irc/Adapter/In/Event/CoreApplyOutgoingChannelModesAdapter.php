<?php

declare(strict_types=1);

namespace App\Irc\Adapter\In\Event;

use App\Irc\Adapter\Network\ApplyOutgoingChannelModesApplicatorInterface;
use App\Irc\Application\Port\In\ApplyOutgoingChannelModesPort;

/**
 * Core implements ApplyOutgoingChannelModesPort: applies MODE sent by services
 * to the channel state so ChannelLookup stays in sync.
 */
final readonly class CoreApplyOutgoingChannelModesAdapter implements ApplyOutgoingChannelModesPort
{
    public function __construct(
        private ApplyOutgoingChannelModesApplicatorInterface $applicator,
    ) {}

    public function applyOutgoingChannelModes(string $channelName, string $modeStr, array $params = []): void
    {
        $this->applicator->applyOutgoingChannelModes($channelName, $modeStr, $params);
    }
}
