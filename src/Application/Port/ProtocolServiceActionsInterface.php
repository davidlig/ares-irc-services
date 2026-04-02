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

    /**
     * Add a network-wide G-line (user@host ban).
     *
     * @param string $serverSid Server SID (source of the TKL/GLINE command)
     * @param string $userMask  User part of the mask (e.g., "*", "nick")
     * @param string $hostMask  Host part of the mask (e.g., "*", "192.168.*")
     * @param int    $duration  Duration in seconds (0 = permanent)
     * @param string $reason    Reason shown to the user
     */
    public function addGline(string $serverSid, string $userMask, string $hostMask, int $duration, string $reason): void;

    /**
     * Remove a network-wide G-line.
     *
     * @param string $serverSid Server SID (source of the TKL/GLINE command)
     * @param string $userMask  User part of the mask (e.g., "*", "nick")
     * @param string $hostMask  Host part of the mask (e.g., "*", "192.168.*")
     */
    public function removeGline(string $serverSid, string $userMask, string $hostMask): void;

    /**
     * Introduce a temporary pseudo-client to the network.
     *
     * Used for GLOBAL messages where a temporary client is needed to send messages.
     * The client is introduced with mode +B (bot) to mark it as a bot.
     *
     * @param string $serverSid Server SID (source of the UID command)
     * @param string $nick      Nickname for the pseudo-client
     * @param string $ident     Username/ident for the pseudo-client
     * @param string $vhost     Virtual hostname (displayed host)
     * @param string $uid       UID for the pseudo-client
     * @param string $realname  Real name (GECOS) for the pseudo-client
     */
    public function introducePseudoClient(string $serverSid, string $nick, string $ident, string $vhost, string $uid, string $realname): void;

    /**
     * Disconnect a pseudo-client from the network.
     *
     * Used after GLOBAL messages to remove the temporary pseudo-client.
     *
     * @param string $serverSid Server SID (source of the QUIT command)
     * @param string $uid       UID of the pseudo-client to disconnect
     * @param string $reason    Quit reason shown to users
     */
    public function quitPseudoClient(string $serverSid, string $uid, string $reason): void;
}
