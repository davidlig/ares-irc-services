<?php

declare(strict_types=1);

namespace App\ChanServ\Application\Port\Out;

use DateTimeImmutable;

interface ChannelRegisterThrottlePort
{
    public function getLastRegistrationAt(int $nickId): ?DateTimeImmutable;

    public function recordRegistration(int $nickId): void;

    public function getRemainingCooldownSeconds(int $nickId, int $minIntervalSeconds): int;

    public function pruneExpiredCooldowns(int $minIntervalSeconds): int;
}
