<?php

declare(strict_types=1);

namespace App\Infrastructure\IRC\Network\Event;

final readonly class UserQuitReceivedEvent
{
    public function __construct(
        public readonly string $sourceId,
        public readonly string $reason,
    ) {
    }
}
