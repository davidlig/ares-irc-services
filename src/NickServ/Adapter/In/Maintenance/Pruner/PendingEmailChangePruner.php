<?php

declare(strict_types=1);

namespace App\NickServ\Adapter\In\Maintenance\Pruner;

use App\Application\Maintenance\InMemoryPrunableInterface;
use App\NickServ\Adapter\Out\InMemory\PendingEmailChangeRegistry;

final readonly class PendingEmailChangePruner implements InMemoryPrunableInterface
{
    public function __construct(
        private PendingEmailChangeRegistry $registry,
    ) {}

    public function prune(): int
    {
        return $this->registry->pruneExpired();
    }
}
