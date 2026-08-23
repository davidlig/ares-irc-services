<?php

declare(strict_types=1);

namespace App\Infrastructure\IRC\Protocol\UnrealUdb\Event;

/**
 * Dispatched when UnrealIRCd sends FDR (Finished Database Replication),
 * indicating that all records for the given block have been sent.
 */
final readonly class UdbSyncCompleteEvent
{
    public function __construct(
        public string $block,
        public string $sourceSid,
    ) {}
}
