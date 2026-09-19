<?php

declare(strict_types=1);

namespace App\NickServ\Application\Port\Out;

use DateTimeImmutable;

interface ResendThrottle
{
    public function remainingCooldownSeconds(string $nickname, int $intervalSeconds, DateTimeImmutable $now): int;

    public function recordResend(string $nickname, DateTimeImmutable $now): void;
}
