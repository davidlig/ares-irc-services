<?php

declare(strict_types=1);

namespace App\Application\Port;

/**
 * Port implemented by Core: send a NOTICE or PRIVMSG to a target UID.
 *
 * Services use this to reply to users without depending on Connection or IRC entities.
 */
interface SendNoticePort
{
    public function sendNotice(string $senderUid, string $targetUid, string $message): void;

    /**
     * @param 'NOTICE'|'PRIVMSG' $messageType
     */
    public function sendMessage(string $senderUid, string $targetUid, string $message, string $messageType): void;
}
