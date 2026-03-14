<?php

declare(strict_types=1);

namespace App\Infrastructure\IRC\Network;

/**
 * Internal interface for applying outgoing channel modes to network state.
 * Allows the Core adapter to be unit-tested without mocking the final NetworkEventEnricher.
 *
 * @internal
 */
interface ApplyOutgoingChannelModesApplicatorInterface
{
    /**
     * @param array<int, string> $params
     */
    public function applyOutgoingChannelModes(string $channelName, string $modeStr, array $params = []): void;
}
