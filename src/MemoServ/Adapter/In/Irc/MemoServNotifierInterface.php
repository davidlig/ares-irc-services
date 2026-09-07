<?php

declare(strict_types=1);

namespace App\MemoServ\Adapter\In\Irc;

/**
 * Sends NOTICE/PRIVMSG as MemoServ (memoserv UID).
 * Implemented by MemoServBot in Adapter/In/Irc/Bot.
 */
interface MemoServNotifierInterface
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
     * Get the service key (e.g., 'memoserv').
     */
    public function getServiceKey(): string;
}
