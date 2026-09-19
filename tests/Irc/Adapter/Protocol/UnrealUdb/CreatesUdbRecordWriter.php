<?php

declare(strict_types=1);

namespace App\Tests\Irc\Adapter\Protocol\UnrealUdb;

use App\Irc\Adapter\Out\Connection\ActiveConnectionHolder;
use App\Irc\Adapter\Protocol\UnrealUdb\Session\UdbSessionStateInterface;
use App\Irc\Adapter\Protocol\UnrealUdb\Synchronization\UdbMutation;
use App\Irc\Adapter\Protocol\UnrealUdb\UnrealUdbRecordWriter;

/**
 * Shared helpers for tests that need a record writer wired to a session
 * state that reports full authority readiness (immediate transmission).
 */
trait CreatesUdbRecordWriter
{
    private function createReadySessionState(): UdbSessionStateInterface
    {
        return new class implements UdbSessionStateInterface {
            public function isAuthorityReady(): bool
            {
                return true;
            }

            public function enqueueMutation(UdbMutation $mutation): void {}

            public function isOperclassGloballyAvailable(string $operclass): bool
            {
                return true;
            }

            public function getAvailableOperclasses(): array
            {
                return [];
            }
        };
    }

    private function createUdbRecordWriter(ActiveConnectionHolder $holder): UnrealUdbRecordWriter
    {
        return new UnrealUdbRecordWriter(
            $this->createReadySessionState(),
            new FakeUdbRecords(),
        );
    }
}
