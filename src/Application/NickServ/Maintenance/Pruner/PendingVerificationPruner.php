<?php

declare(strict_types=1);

namespace App\Application\NickServ\Maintenance\Pruner;

use App\Application\Maintenance\InMemoryPrunableInterface;
use App\Application\NickServ\PendingVerificationRegistry;

final readonly class PendingVerificationPruner implements InMemoryPrunableInterface
{
    public function __construct(
        private readonly PendingVerificationRegistry $registry,
        private readonly int $maxAgeSecondsForResend = 86400,
    ) {
    }

    public function prune(): int
    {
        return $this->registry->pruneExpired($this->maxAgeSecondsForResend);
    }
}
