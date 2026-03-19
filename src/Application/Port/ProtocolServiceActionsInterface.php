<?php

declare(strict_types=1);

namespace App\Application\Port;

/**
 * Port implemented per IRCd protocol: send service-side commands (account, mode,
 * forcenick, kill, channel modes) in the wire format of the active protocol.
 * The bot delegates here so that Unreal, InspIRCd and future protocols can use
 * their own commands (SVS2MODE, SAMODE, MODE, etc.).
 */
interface ProtocolServiceActionsInterface
{
    public function setUserAccount(string $serverSid, string $targetUid, string $accountName): void;

    public function setUserMode(string $serverSid, string $targetUid, string $modes): void;

    public function forceNick(string $serverSid, string $targetUid, string $newNick): void;

    public function killUser(string $serverSid, string $targetUid, string $reason): void;

    /**
     * Set channel modes (e.g. +nt). Params for modes that require a value (e.g. +k key).
     * Source of the MODE line is $serviceUid when non-empty (e.g. :ChanServ MODE #chan +nt).
     */
    public function setChannelModes(string $serverSid, string $channelName, string $modeStr, array $params = [], string $serviceUid = ''): void;

    /**
     * Set or remove a member prefix mode (+o/-o, +v/-v, etc.) for a user in the channel.
     * Source of the MODE line is $serviceUid when non-empty.
     */
    public function setChannelMemberMode(string $serverSid, string $channelName, string $targetUid, string $modeLetter, bool $add, string $serviceUid = ''): void;

    /** Send INVITE for target user to the channel. Source is $serviceUid when non-empty. */
    public function inviteUserToChannel(string $serverSid, string $channelName, string $targetUid, string $serviceUid = ''): void;

    /**
     * Service pseudo-client joins the channel with the given max prefix (e.g. q for owner).
     *
     * @param int|null $channelTimestamp When adding to an existing channel, use its creation TS for SJOIN; omit for new channel (use current time)
     */
    public function joinChannelAsService(string $serverSid, string $channelName, string $serviceUid, string $maxPrefixLetter, ?int $channelTimestamp = null): void;

    /**
     * Set or clear the channel topic. Null = clear. Source of the TOPIC line is $serviceUid when non-empty.
     */
    public function setChannelTopic(string $serverSid, string $channelName, ?string $topic, string $serviceUid = ''): void;

    /**
     * Kick a user from a channel. Source is $serviceUid when non-empty.
     *
     * @param string $serverSid   Server SID (source of the KICK command)
     * @param string $channelName Channel name (e.g., #channel)
     * @param string $targetUid   Target user's UID
     * @param string $reason      Kick reason
     * @param string $serviceUid  Optional service UID as source (e.g., ChanServ's UID)
     */
    public function kickFromChannel(string $serverSid, string $channelName, string $targetUid, string $reason, string $serviceUid = ''): void;
}
