<?php

declare(strict_types=1);

namespace App\Application\NickServ\Maintenance\Pruner;

use App\Application\Maintenance\InMemoryPrunableInterface;
use App\Application\NickServ\IdentifyFailedAttemptRegistry;

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
