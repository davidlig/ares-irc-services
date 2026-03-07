<?php

declare(strict_types=1);

namespace App\Infrastructure\IRC\ServiceBridge;

use App\Application\IRC\BurstCompleteRegistry;
use App\Application\Port\BurstCompletePort;

/**
 * Core implements BurstCompletePort: exposes burst-complete state to Services
 * without them depending on Application\IRC or BurstCompleteRegistry.
 */
final readonly class CoreBurstCompleteAdapter implements BurstCompletePort
{
    public function __construct(
        private BurstCompleteRegistry $burstCompleteRegistry,
    ) {
    }

    public function isComplete(): bool
    {
        return $this->burstCompleteRegistry->isBurstComplete();
    }
}
