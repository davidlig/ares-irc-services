<?php

declare(strict_types=1);

namespace App\NickServ\Adapter\In\Maintenance\Pruner;

use App\Irc\Application\Port\In\Maintenance\InMemoryPrunableInterface;
use App\NickServ\Adapter\Out\InMemory\IdentifyFailedAttemptRegistry;
use App\NickServ\Application\Port\Out\Clock;

final readonly class IdentifyFailedAttemptPruner implements InMemoryPrunableInterface
{
    public function __construct(
        private IdentifyFailedAttemptRegistry $registry,
        private Clock $clock,
        private int $windowSeconds,
    ) {}

    public function prune(): int
    {
        return $this->registry->pruneStale($this->windowSeconds, $this->clock->now());
    }
}
