<?php

declare(strict_types=1);

namespace App\Irc\Application\PublishedEvent;

final readonly class UserJoinedChannelEvent
{
    public function __construct(
        public string $uid,
        public string $channelName,
        public ?string $initialRole = null,
    ) {}
}
