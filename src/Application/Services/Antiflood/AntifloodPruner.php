<?php

declare(strict_types=1);

namespace App\Application\Services\Antiflood;

use App\Application\Maintenance\InMemoryPrunableInterface;

/**
 * Maintenance pruner for AntifloodRegistry.
 * Removes stale client keys whose timestamps are all outside the configured window.
 */
final readonly class AntifloodPruner implements InMemoryPrunableInterface
{
    public function __construct(
        private AntifloodRegistry $registry,
        private int $windowSeconds,
    ) {
    }

    public function prune(): int
    {
        return $this->registry->pruneStale($this->windowSeconds);
    }
}
