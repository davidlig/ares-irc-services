<?php

declare(strict_types=1);

namespace App\Irc\Application\Port\In;

/**
 * Port implemented by Core: resolve a channel by name for Services.
 *
 * Services use this to obtain ChannelView (never Domain\IRC entities).
 */
interface ChannelLookupPort
{
    public function findByChannelName(string $channelName): ?ChannelView;

    /**
     * @return ChannelView[] All channels currently on the network
     */
    public function listAll(): array;

    /** @return iterable<ChannelModeView> Channel name and modes only, without member projections. */
    public function iterateModeSnapshots(): iterable;
}
