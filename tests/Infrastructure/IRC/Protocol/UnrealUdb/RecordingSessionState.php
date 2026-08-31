<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\IRC\Protocol\UnrealUdb;

use App\Application\Port\UdbMutation;
use App\Infrastructure\IRC\Protocol\UnrealUdb\UdbSessionStateInterface;

/**
 * UdbSessionStateInterface test double recording the mutation queue.
 */
final class RecordingSessionState implements UdbSessionStateInterface
{
    /** @var list<UdbMutation> */
    public array $queue = [];

    public function __construct(public bool $ready) {}

    public function isAuthorityReady(): bool
    {
        return $this->ready;
    }

    public function enqueueMutation(UdbMutation $mutation): void
    {
        $this->queue[] = $mutation;
    }
}
