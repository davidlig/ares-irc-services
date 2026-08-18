<?php

declare(strict_types=1);

namespace App\Infrastructure\IRC\Protocol\UnrealUdb\Event;

final readonly class UdbSyncRequestedEvent
{
    public function __construct(
        public string $block,
        public string $sourceSid,
    ) {}
}
