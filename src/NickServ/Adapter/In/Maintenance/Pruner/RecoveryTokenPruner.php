<?php

declare(strict_types=1);

namespace App\NickServ\Adapter\In\Maintenance\Pruner;

use App\Application\Maintenance\InMemoryPrunableInterface;
use App\NickServ\Adapter\Out\InMemory\RecoveryTokenRegistry;

final readonly class RecoveryTokenPruner implements InMemoryPrunableInterface
{
    public function __construct(
        private RecoveryTokenRegistry $registry,
        private int $maxAgeSecondsForRecover = 86400,
    ) {}

    public function prune(): int
    {
        return $this->registry->pruneExpired($this->maxAgeSecondsForRecover);
    }
}
