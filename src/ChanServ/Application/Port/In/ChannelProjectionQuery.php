<?php

declare(strict_types=1);

namespace App\ChanServ\Application\Port\In;

interface ChannelProjectionQuery
{
    /** @return list<ChannelProjection> */
    public function all(): array;

    /** Finds the channel registered as $channelName under the negotiated casemapping. */
    public function findByName(string $channelName): ?ChannelProjection;

    /**
     * Finds the channel whose stored RFC 1459 key equals the key of $channelNameKey.
     * For callers that hold a stored key (for example an event's channelNameLower).
     */
    public function findByNameKey(string $channelNameKey): ?ChannelProjection;
}
