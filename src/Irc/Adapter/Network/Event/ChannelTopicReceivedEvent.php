<?php

declare(strict_types=1);

namespace App\Irc\Adapter\Network\Event;

use App\Irc\Domain\ValueObject\ChannelName;

final readonly class ChannelTopicReceivedEvent
{
    public function __construct(
        public ChannelName $channelName,
        public ?string $topic,
        public ?string $setterNick = null,
        public ?string $sourceUid = null,
    ) {}
}
