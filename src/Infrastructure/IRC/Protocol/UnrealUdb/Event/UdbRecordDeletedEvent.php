<?php

declare(strict_types=1);

namespace App\Infrastructure\IRC\Protocol\UnrealUdb\Event;

/**
 * Dispatched when UDB reports a record without a value during a block sync.
 */
final readonly class UdbRecordDeletedEvent
{
    public function __construct(
        public string $key,
    ) {}
}
