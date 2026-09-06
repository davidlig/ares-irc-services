<?php

declare(strict_types=1);

namespace App\NickServ\Adapter\In\Maintenance\Pruner;

use App\Application\Maintenance\InMemoryPrunableInterface;
use App\NickServ\Adapter\Out\InMemory\PendingVerificationRegistry;

final readonly class PendingVerificationPruner implements InMemoryPrunableInterface
{
    public function __construct(
        private PendingVerificationRegistry $registry,
        private int $maxAgeSecondsForResend = 86400,
    ) {}

    public function prune(): int
    {
        return $this->registry->pruneExpired($this->maxAgeSecondsForResend);
    }
}
