<?php

declare(strict_types=1);

namespace App\Application\NickServ\Maintenance\Pruner;

use App\Application\Maintenance\InMemoryPrunableInterface;
use App\Application\NickServ\RegisterThrottleRegistry;

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
