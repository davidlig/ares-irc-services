<?php

declare(strict_types=1);

namespace App\NickServ\Adapter\In\Maintenance\Pruner;

use App\Irc\Application\Port\In\Maintenance\InMemoryPrunableInterface;
use App\NickServ\Adapter\Out\InMemory\PendingVerificationRegistry;
use App\NickServ\Application\Port\Out\Clock;

final readonly class PendingVerificationPruner implements InMemoryPrunableInterface
{
    public function __construct(
        private PendingVerificationRegistry $registry,
        private Clock $clock,
        private int $maxAgeSecondsForResend = 86400,
    ) {}

    public function prune(): int
    {
        return $this->registry->pruneExpired($this->clock->now(), $this->maxAgeSecondsForResend);
    }
}
