<?php

declare(strict_types=1);

namespace App\Irc\Application\Port\In;

interface SendCtcpPort
{
    public function sendCtcpReply(string $senderUid, string $targetUid, string $command, string $response): void;
}
