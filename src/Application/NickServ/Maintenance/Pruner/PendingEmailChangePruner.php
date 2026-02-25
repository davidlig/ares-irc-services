<?php

declare(strict_types=1);

namespace App\Application\NickServ\Maintenance\Pruner;

use App\Application\Maintenance\InMemoryPrunableInterface;
use App\Application\NickServ\PendingEmailChangeRegistry;

final readonly class PendingEmailChangePruner implements InMemoryPrunableInterface
{
    public function __construct(
        private readonly PendingEmailChangeRegistry $registry,
    ) {
    }

    public function prune(): int
    {
        return $this->registry->pruneExpired();
    }
}
