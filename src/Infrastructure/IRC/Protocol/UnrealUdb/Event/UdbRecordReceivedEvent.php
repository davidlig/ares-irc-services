<?php

declare(strict_types=1);

namespace App\Infrastructure\IRC\Protocol\UnrealUdb\Event;

/**
 * Dispatched when an incoming DB record is received from UnrealUdb.
 */
final readonly class UdbRecordReceivedEvent
{
    public function __construct(
        public string $key,
        public string $value,
    ) {}
}
