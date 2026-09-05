<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\IRC\Protocol\UnrealUdb;

use App\Application\Port\UdbMutation;
use App\Domain\Udb\Repository\UdbRecordRepositoryInterface;
use App\Infrastructure\IRC\Connection\ActiveConnectionHolder;
use App\Infrastructure\IRC\Protocol\UnrealUdb\UdbSessionStateInterface;
use App\Infrastructure\IRC\Protocol\UnrealUdb\UnrealUdbRecordWriter;

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
        };
    }

    private function createUdbRecordWriter(ActiveConnectionHolder $holder): UnrealUdbRecordWriter
    {
        return new UnrealUdbRecordWriter(
            $holder,
            $this->createReadySessionState(),
            $this->createStub(UdbRecordRepositoryInterface::class),
            '001',
        );
    }
}
