<?php

declare(strict_types=1);

namespace App\Application\Port;

/**
 * Port to apply outgoing channel MODE changes to Core state when services send MODE.
 * Keeps the Core channel view in sync so e.g. SET MLOCK ON reads the actual channel state
 * (including modes we just applied like +r, +MR).
 */
interface ApplyOutgoingChannelModesPort
{
    /**
     * Apply the given mode delta and params to the channel state in Core.
     * Call after sending MODE to the wire so the next ChannelLookup reflects the new state.
     *
     * @param array<int, string> $params Params in wire order for modes that take a param (e.g. +l 100)
     */
    public function applyOutgoingChannelModes(string $channelName, string $modeStr, array $params = []): void;
}
