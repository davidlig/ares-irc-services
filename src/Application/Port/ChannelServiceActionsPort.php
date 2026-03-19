<?php

declare(strict_types=1);

namespace App\Application\Port;

/**
 * Port for service-side channel actions (set modes, member prefixes, invite, join).
 *
 * Implemented by ChanServ bot using the active ProtocolModule. Services do not
 * know wire format (MODE, SAMODE, SJOIN, INVITE); all output goes through protocol.
 */
interface ChannelServiceActionsPort
{
    public function setChannelModes(string $channelName, string $modeStr, array $params = []): void;

    public function setChannelMemberMode(string $channelName, string $targetUid, string $modeLetter, bool $add): void;

    public function inviteToChannel(string $channelName, string $targetUid): void;

    /**
     * Service pseudo-client joins the channel with maximum level (e.g. +q in Unreal).
     *
     * @param int|null $channelTimestamp When adding to an existing channel, pass its creation timestamp (from ChannelView) so SJOIN uses the same TS; omit for new channel
     */
    public function joinChannelAsService(string $channelName, ?int $channelTimestamp = null): void;

    /**
     * Set or clear the channel topic. Null = clear topic.
     */
    public function setChannelTopic(string $channelName, ?string $topic): void;

    /**
     * Kick a user from a channel.
     *
     * @param string $channelName Channel name (e.g., #channel)
     * @param string $targetUid   Target user's UID
     * @param string $reason      Kick reason shown to the user
     */
    public function kickFromChannel(string $channelName, string $targetUid, string $reason): void;
}
