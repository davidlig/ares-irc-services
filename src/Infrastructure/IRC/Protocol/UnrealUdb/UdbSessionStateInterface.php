<?php

declare(strict_types=1);

namespace App\Infrastructure\IRC\Protocol\UnrealUdb;

use App\Application\Port\UdbMutation;

/**
 * Read/write surface the UDB record writer uses to coordinate with the
 * session state machine. Implemented by UdbSessionCoordinator.
 */
interface UdbSessionStateInterface
{
    /**
     * True when the link may send real-time record mutations: HEL confirmed
     * both ways, the peer selected us as its propagator, the authoritative
     * store is initialized, the reconciliation barrier completed and no
     * staged snapshot transfer is in flight.
     */
    public function isAuthorityReady(): bool;

    /**
     * Queues a mutation for later delivery when the link is not ready yet.
     * The queue is bounded; on overflow a reconciliation round recovers the
     * divergence via snapshots (the store already holds every change).
     */
    public function enqueueMutation(UdbMutation $mutation): void;

    /** True only when the latest complete OCLG projection contains the operclass. */
    public function isOperclassGloballyAvailable(string $operclass): bool;
}
