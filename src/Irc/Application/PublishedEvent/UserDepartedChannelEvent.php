<?php

declare(strict_types=1);

namespace App\Irc\Application\PublishedEvent;

final readonly class UserDepartedChannelEvent
{
    public function __construct(
        public string $uid,
        public string $channelName,
    ) {}
}
