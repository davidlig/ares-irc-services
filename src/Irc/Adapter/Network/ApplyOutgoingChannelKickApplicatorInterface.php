<?php

declare(strict_types=1);

namespace App\Irc\Adapter\Network;

/**
 * Internal interface for applying outgoing channel kicks to network state.
 * Allows the Core adapter to be unit-tested without mocking the final NetworkEventEnricher.
 *
 * @internal
 */
interface ApplyOutgoingChannelKickApplicatorInterface
{
    public function applyOutgoingKick(string $channelName, string $targetUid, string $reason): void;
}
