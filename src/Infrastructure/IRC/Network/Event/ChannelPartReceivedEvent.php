<?php

declare(strict_types=1);

namespace App\Infrastructure\IRC\Network\Event;

use App\Domain\IRC\ValueObject\ChannelName;

final readonly class ChannelPartReceivedEvent
{
    public function __construct(
        public readonly string $sourceId,
        public readonly ChannelName $channelName,
        public readonly string $reason,
        public readonly bool $wasKicked = false,
    ) {
    }
}
