<?php

declare(strict_types=1);

namespace App\Irc\Application\Antiflood;

use App\Irc\Application\Port\In\Maintenance\InMemoryPrunableInterface;
use App\Irc\Application\Port\Out\AntifloodClock;

/**
 * Maintenance pruner for AntifloodRegistry.
 * Removes stale client keys whose timestamps are all outside the configured window.
 */
final readonly class AntifloodPruner implements InMemoryPrunableInterface
{
    public function __construct(
        private AntifloodRegistry $registry,
        private AntifloodClock $clock,
        private int $windowSeconds,
    ) {}

    public function prune(): int
    {
        return $this->registry->pruneStale($this->windowSeconds, $this->clock->now());
    }
}
