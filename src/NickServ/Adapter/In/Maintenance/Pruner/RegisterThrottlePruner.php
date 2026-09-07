<?php

declare(strict_types=1);

namespace App\NickServ\Adapter\In\Maintenance\Pruner;

use App\Irc\Application\Port\In\Maintenance\InMemoryPrunableInterface;
use App\NickServ\Adapter\Out\InMemory\RegisterThrottleRegistry;
use App\NickServ\Application\Port\Out\Clock;

final readonly class RegisterThrottlePruner implements InMemoryPrunableInterface
{
    public function __construct(
        private RegisterThrottleRegistry $registry,
        private Clock $clock,
        private int $minIntervalSeconds,
    ) {}

    public function prune(): int
    {
        return $this->registry->pruneExpiredCooldowns($this->minIntervalSeconds, $this->clock->now());
    }
}
