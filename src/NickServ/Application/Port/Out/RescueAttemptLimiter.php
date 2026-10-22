<?php

declare(strict_types=1);

namespace App\NickServ\Application\Port\Out;

use DateTimeImmutable;

interface RescueAttemptLimiter
{
    public function remainingCooldownSeconds(int $accountId, string $clientKey, DateTimeImmutable $now): int;

    public function recordFailure(int $accountId, string $clientKey, DateTimeImmutable $now): void;

    /** Success never erases shared account/client budgets; failures expire naturally. */
    public function clearFailures(int $accountId, string $clientKey): void;

    public function prune(DateTimeImmutable $now): int;
}
