<?php

declare(strict_types=1);

namespace App\NickServ\Adapter\In\Maintenance\Pruner;

use App\Irc\Application\Port\In\Maintenance\InMemoryPrunableInterface;
use App\NickServ\Adapter\Out\InMemory\PendingEmailChangeRegistry;
use App\NickServ\Application\Port\Out\Clock;

final readonly class PendingEmailChangePruner implements InMemoryPrunableInterface
{
    public function __construct(
        private PendingEmailChangeRegistry $registry,
        private Clock $clock,
    ) {}

    public function prune(): int
    {
        return $this->registry->pruneExpired($this->clock->now());
    }
}
