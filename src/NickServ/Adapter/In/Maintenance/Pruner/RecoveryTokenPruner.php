<?php

declare(strict_types=1);

namespace App\NickServ\Adapter\In\Maintenance\Pruner;

use App\Irc\Application\Port\In\Maintenance\InMemoryPrunableInterface;
use App\NickServ\Adapter\Out\InMemory\RecoveryTokenRegistry;
use App\NickServ\Application\Port\Out\Clock;

final readonly class RecoveryTokenPruner implements InMemoryPrunableInterface
{
    public function __construct(
        private RecoveryTokenRegistry $registry,
        private Clock $clock,
        private int $maxAgeSecondsForRecover = 86400,
    ) {}

    public function prune(): int
    {
        return $this->registry->pruneExpired($this->clock->now(), $this->maxAgeSecondsForRecover);
    }
}
