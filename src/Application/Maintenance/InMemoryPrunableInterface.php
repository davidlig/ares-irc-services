<?php

declare(strict_types=1);

namespace App\Application\Maintenance;

/**
 * Contract for in-memory registries (or adapters) that can prune stale entries
 * during maintenance. Used by PruneMemoryRegistriesTask to run cleanup without
 * knowing concrete registry types.
 */
interface InMemoryPrunableInterface
{
    /**
     * Removes obsolete entries and returns the number of entries removed.
     * Safe to call when there is nothing to prune (returns 0).
     */
    public function prune(): int;
}
