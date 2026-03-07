<?php

declare(strict_types=1);

namespace App\Infrastructure\IRC\ServiceBridge;

use App\Application\Port\ApplyOutgoingChannelModesPort;
use App\Infrastructure\IRC\Network\NetworkEventEnricher;

/**
 * Core implements ApplyOutgoingChannelModesPort: applies MODE sent by services
 * to the channel state so ChannelLookup stays in sync.
 */
final readonly class CoreApplyOutgoingChannelModesAdapter implements ApplyOutgoingChannelModesPort
{
    public function __construct(
        private NetworkEventEnricher $networkEventEnricher,
    ) {
    }

    public function applyOutgoingChannelModes(string $channelName, string $modeStr, array $params = []): void
    {
        $this->networkEventEnricher->applyOutgoingChannelModes($channelName, $modeStr, $params);
    }
}
