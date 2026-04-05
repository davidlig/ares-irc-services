<?php

declare(strict_types=1);

namespace App\Application\MemoServ\Command;

/**
 * Sends NOTICE/PRIVMSG as MemoServ (memoserv UID).
 * Implemented by MemoServBot in Infrastructure.
 */
interface MemoServNotifierInterface
{
    public function sendNotice(string $targetUidOrNick, string $message): void;

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
