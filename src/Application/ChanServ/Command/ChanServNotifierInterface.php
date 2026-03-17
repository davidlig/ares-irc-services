<?php

declare(strict_types=1);

namespace App\Application\ChanServ\Command;

/**
 * Abstracts IRC send and channel actions for ChanServ.
 * Implemented by ChanServBot in Infrastructure.
 */
interface ChanServNotifierInterface
{
    public function sendNotice(string $targetUidOrNick, string $message): void;

    /**
     * @param 'NOTICE'|'PRIVMSG' $messageType
     */
    public function sendMessage(string $targetUidOrNick, string $message, string $messageType): void;

    /** Send a NOTICE to a channel (e.g. "Ares da +o a davidlig"). */
    public function sendNoticeToChannel(string $channelName, string $message): void;

    public function setChannelModes(string $channelName, string $modeStr, array $params = []): void;

    public function setChannelMemberMode(string $channelName, string $targetUid, string $modeLetter, bool $add): void;

    public function inviteToChannel(string $channelName, string $targetUid): void;

    public function joinChannelAsService(string $channelName, ?int $channelTimestamp = null): void;

    /**
     * Get the bot's nickname.
     */
    public function getNick(): string;
}
