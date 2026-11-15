<?php

declare(strict_types=1);

namespace App\ChanServ\Application\Port\Out;

interface RegisteredChannelBurstSnapshot
{
    public function beginBurst(): void;

    public function endBurst(): void;

    /** Whether the name resolves to a registration as findByChannelName() does; null when no burst snapshot is active. */
    public function contains(string $channelName): ?bool;
}
