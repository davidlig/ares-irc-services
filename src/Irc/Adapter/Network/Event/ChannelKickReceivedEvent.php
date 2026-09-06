<?php

declare(strict_types=1);

namespace App\Irc\Adapter\Network\Event;

use App\Irc\Domain\ValueObject\ChannelName;

final readonly class ChannelKickReceivedEvent
{
    public function __construct(
        public ChannelName $channelName,
        public string $targetId,
        public string $reason,
    ) {}
}
