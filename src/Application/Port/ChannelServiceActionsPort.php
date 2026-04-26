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

    /**
     * Invite a user to a channel.
     *
     * @param int|null $channelTimestamp Channel creation timestamp (required by InspIRCd for TS validation)
     */
    public function inviteToChannel(string $channelName, string $targetUid, ?int $channelTimestamp = null): void;

    /**
     * Service pseudo-client joins the channel with maximum level (e.g. +q in Unreal).
     *
     * @param int|null $channelTimestamp When adding to an existing channel, pass its creation timestamp (from ChannelView) so SJOIN uses the same TS; omit for new channel
     */
    public function joinChannelAsService(string $channelName, ?int $channelTimestamp = null): void;

    /**
     * Set or clear the channel topic. Null = clear topic.
     *
     * @param int|null $channelCreationTs Channel creation timestamp (for protocols like InspIRCd v4 FTOPIC)
     */
    public function setChannelTopic(string $channelName, ?string $topic, ?int $channelCreationTs = null): void;

    /**
     * Kick a user from a channel.
     *
     * @param string $channelName Channel name (e.g., #channel)
     * @param string $targetUid   Target user's UID
     * @param string $reason      Kick reason shown to the user
     */
    public function kickFromChannel(string $channelName, string $targetUid, string $reason): void;

    /**
     * Service pseudo-client leaves a channel.
     */
    public function partChannelAsService(string $channelName): void;
}
