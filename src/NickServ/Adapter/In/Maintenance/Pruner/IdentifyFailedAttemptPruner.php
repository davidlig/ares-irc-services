<?php

declare(strict_types=1);

namespace App\NickServ\Adapter\In\Maintenance\Pruner;

use App\Application\Maintenance\InMemoryPrunableInterface;
use App\NickServ\Adapter\Out\InMemory\IdentifyFailedAttemptRegistry;

final readonly class IdentifyFailedAttemptPruner implements InMemoryPrunableInterface
{
    public function __construct(
        private IdentifyFailedAttemptRegistry $registry,
        private int $windowSeconds,
    ) {}

    public function prune(): int
    {
        return $this->registry->pruneStale($this->windowSeconds);
    }
}
