<?php

declare(strict_types=1);

namespace App\Application\Port;

/**
 * Tracks channels that services join locally so the channel repository
 * stays in sync with the IRCd state. When a service pseudo-client joins
 * a channel via SJOIN/FJOIN, the channel and member must also be recorded
 * in the local repository so that ChannelLookupPort can find them and
 * provide the correct channel timestamp for subsequent protocol commands
 * (FMODE, FTOPIC, INVITE, etc.).
 */
interface ServiceChannelRegistrationPort
{
    /**
     * Register a service pseudo-client as a member of a channel in the local repository.
     *
     * If the channel already exists, the service member is added (or updated).
     * If the channel does not exist, it is created with the given timestamp and modes.
     *
     * @param string $channelName         Channel name (e.g. "#ircops")
     * @param string $serviceUid          UID of the service pseudo-client
     * @param string $servicePrefixLetter Prefix mode letter ('q', 'a', 'o', 'h', 'v', or '')
     * @param int    $channelTimestamp    Channel creation timestamp
     * @param string $modes               Channel modes to set (e.g. "+rP")
     */
    public function registerServiceChannelJoin(
        string $channelName,
        string $serviceUid,
        string $servicePrefixLetter,
        int $channelTimestamp,
        string $modes = '',
    ): void;

    /**
     * Remove a service pseudo-client from a channel in the local repository.
     *
     * If the service was the last member, the channel is removed entirely.
     *
     * @param string $channelName Channel name (e.g. "#ircops")
     * @param string $serviceUid  UID of the service pseudo-client
     */
    public function unregisterServiceChannelPart(string $channelName, string $serviceUid): void;
}
