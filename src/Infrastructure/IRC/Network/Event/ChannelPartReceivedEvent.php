<?php

declare(strict_types=1);

namespace App\Infrastructure\IRC\Network\Event;

use App\Domain\IRC\ValueObject\ChannelName;

final readonly class ChannelPartReceivedEvent
{
    public function __construct(
        public string $sourceId,
        public ChannelName $channelName,
        public string $reason,
        public bool $wasKicked = false,
    ) {}
}
