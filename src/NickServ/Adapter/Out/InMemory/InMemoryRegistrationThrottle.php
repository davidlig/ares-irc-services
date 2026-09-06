<?php

declare(strict_types=1);

namespace App\NickServ\Adapter\Out\InMemory;

use App\Application\NickServ\RegisterThrottleRegistry;
use App\NickServ\Application\Port\Out\RegistrationThrottle;
use DateTimeImmutable;

final readonly class InMemoryRegistrationThrottle implements RegistrationThrottle
{
    public function __construct(private RegisterThrottleRegistry $registry) {}

    public function remainingCooldownSeconds(string $clientKey, int $minimumIntervalSeconds, DateTimeImmutable $now): int
    {
        return $this->registry->getRemainingCooldownSeconds($clientKey, $minimumIntervalSeconds, $now);
    }

    public function recordAttempt(string $clientKey, DateTimeImmutable $now): void
    {
        $this->registry->recordAttempt($clientKey, $now);
    }
}
