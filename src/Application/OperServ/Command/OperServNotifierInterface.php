<?php

declare(strict_types=1);

namespace App\Application\OperServ\Command;

interface OperServNotifierInterface
{
    public function sendNotice(string $targetUidOrNick, string $message): void;

    public function sendMessage(string $targetUidOrNick, string $message, string $messageType): void;

    public function getNick(): string;

    public function getUid(): string;
}
