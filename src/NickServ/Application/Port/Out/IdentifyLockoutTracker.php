<?php

declare(strict_types=1);

namespace App\NickServ\Application\Port\Out;

interface IdentifyLockoutTracker
{
    public function getRemainingLockoutSeconds(
        string $clientKey,
        int $maxAttempts,
        int $windowSeconds,
        int $lockoutSeconds,
    ): int;

    public function recordFailedAttempt(string $clientKey, int $windowSeconds): void;

    public function clearFailedAttempts(string $clientKey): void;
}
