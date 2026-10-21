<?php

declare(strict_types=1);

namespace App\NickServ\Adapter\In\Maintenance\Pruner;

use App\Irc\Application\Port\In\Maintenance\InMemoryPrunableInterface;
use App\NickServ\Application\Port\Out\Clock;
use App\NickServ\Application\Port\Out\RescueAttemptLimiter;
use App\NickServ\Application\Port\Out\TemporaryRescueTokenStore;

final readonly class RescueSecurityPruner implements InMemoryPrunableInterface
{
    public function __construct(private TemporaryRescueTokenStore $tokens, private RescueAttemptLimiter $attempts, private Clock $clock) {}

    public function prune(): int
    {
        $now = $this->clock->now();

        return $this->tokens->prune($now) + $this->attempts->prune($now);
    }
}
