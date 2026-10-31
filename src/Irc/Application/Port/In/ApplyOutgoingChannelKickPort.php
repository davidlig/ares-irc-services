<?php

declare(strict_types=1);

namespace App\Irc\Application\Port\In;

/**
 * Port to apply outgoing channel KICK changes to Core state when services send KICK.
 * Keeps the Core channel view in sync so subsequent operations reflect that the
 * kicked user has left the channel.
 */
interface ApplyOutgoingChannelKickPort
{
    /**
     * Apply the outgoing kick to the channel state in Core.
     * Call after sending KICK to the wire so the next ChannelLookup reflects the new state.
     */
    public function applyOutgoingKick(string $channelName, string $targetUid, string $reason): void;
}
