<?php

declare(strict_types=1);

namespace App\Irc\Application\PublishedEvent;

final readonly class UserModesChangedEvent
{
    public function __construct(
        public string $uid,
        public string $modeDelta,
    ) {}
}
