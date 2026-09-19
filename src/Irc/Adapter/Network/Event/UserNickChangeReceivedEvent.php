<?php

declare(strict_types=1);

namespace App\Irc\Adapter\Network\Event;

final readonly class UserNickChangeReceivedEvent
{
    public function __construct(
        public string $sourceId,
        public string $newNickStr,
    ) {}
}
