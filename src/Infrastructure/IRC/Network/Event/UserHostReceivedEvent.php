<?php

declare(strict_types=1);

namespace App\Infrastructure\IRC\Network\Event;

final readonly class UserHostReceivedEvent
{
    public function __construct(
        public string $sourceId,
        public string $newHost,
    ) {}
}
