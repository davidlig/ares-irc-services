<?php

declare(strict_types=1);

namespace App\Irc\Application\Port\In;

interface LocalUserQuitSyncPort
{
    public function apply(string $uid, string $reason): void;
}
