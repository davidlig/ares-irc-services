<?php

declare(strict_types=1);

namespace App\MemoServ\Application\Port\Out;

interface MemoThrottlePort
{
    /**
     * Returns the number of seconds the sender must wait before SEND is allowed again,
     * or 0 if allowed.
     */
    public function getRemainingCooldownSeconds(string $senderUid, int $minIntervalSeconds): int;

    public function recordSend(string $senderUid): void;
}
