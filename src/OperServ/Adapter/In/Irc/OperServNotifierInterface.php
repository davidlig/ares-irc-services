<?php

declare(strict_types=1);

namespace App\OperServ\Adapter\In\Irc;

interface OperServNotifierInterface
{
    public function sendNotice(string $targetUidOrNick, string $message): void;

    /** @param 'NOTICE'|'PRIVMSG' $messageType */
    public function sendMessage(string $targetUidOrNick, string $message, string $messageType): void;

    public function getNick(): string;

    public function getUid(): string;

    public function getServiceKey(): string;
}
