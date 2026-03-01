<?php

declare(strict_types=1);

namespace App\Application\Port;

/**
 * Port implemented by Core: send a NOTICE or PRIVMSG to a target UID.
 *
 * Services use this to reply to users without depending on Connection or IRC entities.
 * $messageType must be 'NOTICE' or 'PRIVMSG'. sendNotice() is equivalent to sendMessage(..., 'NOTICE').
 */
interface SendNoticePort
{
    public function sendNotice(string $targetUid, string $message): void;

    /**
     * @param 'NOTICE'|'PRIVMSG' $messageType
     */
    public function sendMessage(string $targetUid, string $message, string $messageType): void;
}
