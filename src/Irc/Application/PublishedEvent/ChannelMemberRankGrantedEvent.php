<?php

declare(strict_types=1);

namespace App\Irc\Application\PublishedEvent;

final readonly class ChannelMemberRankGrantedEvent
{
    public function __construct(
        public string $channelName,
        public string $uid,
        public string $rank,
    ) {}
}
