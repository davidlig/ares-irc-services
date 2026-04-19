<?php

declare(strict_types=1);

namespace App\Infrastructure\IRC\Network\Event;

final readonly class UserMetadataReceivedEvent
{
    public function __construct(
        public readonly string $targetUid,
        public readonly string $key,
        public readonly string $value,
    ) {
    }
}
