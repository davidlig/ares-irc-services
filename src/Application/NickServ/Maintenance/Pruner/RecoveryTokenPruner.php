<?php

declare(strict_types=1);

namespace App\Application\NickServ\Maintenance\Pruner;

use App\Application\Maintenance\InMemoryPrunableInterface;
use App\Application\NickServ\RecoveryTokenRegistry;

final readonly class RecoveryTokenPruner implements InMemoryPrunableInterface
{
    public function __construct(
        private readonly RecoveryTokenRegistry $registry,
        private readonly int $maxAgeSecondsForRecover = 86400,
    ) {
    }

    public function prune(): int
    {
        return $this->registry->pruneExpired($this->maxAgeSecondsForRecover);
    }
}
