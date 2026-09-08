<?php

declare(strict_types=1);

namespace App\Shared\Application\Port;

interface SendCtcpPort
{
    public function sendCtcpReply(string $senderUid, string $targetUid, string $command, string $response): void;
}
