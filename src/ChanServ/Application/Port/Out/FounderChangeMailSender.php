<?php

declare(strict_types=1);

namespace App\ChanServ\Application\Port\Out;

interface FounderChangeMailSender
{
    public function sendFounderChangeToken(
        string $email,
        string $channelName,
        string $newNickname,
        string $token,
        string $botNick,
        string $locale,
    ): void;
}
