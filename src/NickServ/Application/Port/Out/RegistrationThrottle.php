<?php

declare(strict_types=1);

namespace App\NickServ\Application\Port\Out;

use DateTimeImmutable;

interface RegistrationThrottle
{
    public function remainingCooldownSeconds(string $clientKey, int $minimumIntervalSeconds, DateTimeImmutable $now): int;

    public function recordAttempt(string $clientKey, DateTimeImmutable $now): void;
}
