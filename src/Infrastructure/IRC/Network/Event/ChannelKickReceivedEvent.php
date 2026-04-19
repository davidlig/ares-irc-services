<?php

declare(strict_types=1);

namespace App\Infrastructure\IRC\Network\Event;

use App\Domain\IRC\ValueObject\ChannelName;

final readonly class ChannelKickReceivedEvent
{
    public function __construct(
        public readonly ChannelName $channelName,
        public readonly string $targetId,
        public readonly string $reason,
    ) {
    }
}
