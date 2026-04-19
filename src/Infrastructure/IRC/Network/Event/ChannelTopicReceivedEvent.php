<?php

declare(strict_types=1);

namespace App\Infrastructure\IRC\Network\Event;

use App\Domain\IRC\ValueObject\ChannelName;

final readonly class ChannelTopicReceivedEvent
{
    public function __construct(
        public readonly ChannelName $channelName,
        public readonly ?string $topic,
        public readonly ?string $setterNick = null,
    ) {
    }
}
