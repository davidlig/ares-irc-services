<?php

declare(strict_types=1);

namespace App\NickServ\Adapter\In\Maintenance\Pruner;

use App\Application\Maintenance\InMemoryPrunableInterface;
use App\NickServ\Adapter\Out\InMemory\RegisterThrottleRegistry;

final readonly class RegisterThrottlePruner implements InMemoryPrunableInterface
{
    public function __construct(
        private RegisterThrottleRegistry $registry,
        private int $minIntervalSeconds,
    ) {}

    public function prune(): int
    {
        return $this->registry->pruneExpiredCooldowns($this->minIntervalSeconds);
    }
}
