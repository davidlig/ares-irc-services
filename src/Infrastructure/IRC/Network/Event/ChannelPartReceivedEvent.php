<?php

declare(strict_types=1);

namespace App\Infrastructure\IRC\Network\Event;

use App\Irc\Domain\ValueObject\ChannelName;

final readonly class ChannelPartReceivedEvent
{
    public function __construct(
        public string $sourceId,
        public ChannelName $channelName,
        public string $reason,
        public bool $wasKicked = false,
    ) {}
}
