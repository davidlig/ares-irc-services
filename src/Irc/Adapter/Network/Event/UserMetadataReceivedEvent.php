<?php

declare(strict_types=1);

namespace App\Irc\Adapter\Network\Event;

final readonly class UserMetadataReceivedEvent
{
    public function __construct(
        public string $targetUid,
        public string $key,
        public string $value,
    ) {}
}
