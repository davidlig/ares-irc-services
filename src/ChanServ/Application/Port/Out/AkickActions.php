<?php

declare(strict_types=1);

namespace App\ChanServ\Application\Port\Out;

interface AkickActions
{
    public function banAndKick(string $channelName, string $uid, string $mask, string $reason): void;
}
