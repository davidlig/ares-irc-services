<?php

declare(strict_types=1);

namespace App\Tests\Irc\Adapter\Protocol\UnrealUdb;

use App\Irc\Adapter\Protocol\UnrealUdb\Session\UdbSessionStateInterface;
use App\Irc\Adapter\Protocol\UnrealUdb\Synchronization\UdbMutation;

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

    public function isOperclassGloballyAvailable(string $operclass): bool
    {
        return false;
    }

    public function getAvailableOperclasses(): array
    {
        return [];
    }
}
