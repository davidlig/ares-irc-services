<?php

declare(strict_types=1);

namespace App\NickServ\Application\Port\Out;

use DateTimeImmutable;

interface IdentifyLockoutTracker
{
    public function getRemainingLockoutSeconds(
        string $clientKey,
        int $maxAttempts,
        int $windowSeconds,
        int $lockoutSeconds,
        DateTimeImmutable $now,
    ): int;

    public function recordFailedAttempt(string $clientKey, int $windowSeconds, DateTimeImmutable $now): void;

    public function clearFailedAttempts(string $clientKey): void;
}
