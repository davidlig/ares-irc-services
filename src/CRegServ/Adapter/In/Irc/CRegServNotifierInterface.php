<?php

declare(strict_types=1);

namespace App\CRegServ\Adapter\In\Irc;

/**
 * Sends NOTICE/PRIVMSG as CReG (cregserv UID).
 * Implemented by CRegServBot in Adapter/In/Irc/Bot.
 */
interface CRegServNotifierInterface
{
    public function sendNotice(string $targetUidOrNick, string $message): void;

    /**
     * @param 'NOTICE'|'PRIVMSG' $messageType
     */
    public function sendMessage(string $targetUidOrNick, string $message, string $messageType): void;

    /**
     * Get the bot's nickname.
     */
    public function getNick(): string;

    /**
     * Get the service key (e.g., 'cregserv').
     */
    public function getServiceKey(): string;
}
