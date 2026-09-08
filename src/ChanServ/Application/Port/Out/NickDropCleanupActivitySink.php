<?php

declare(strict_types=1);

namespace App\ChanServ\Application\Port\Out;

interface NickDropCleanupActivitySink
{
    public function founderTransferred(int $channelId, string $channelName, int $newFounderNickId): void;

    public function channelDropped(int $channelId, string $channelName): void;
}
