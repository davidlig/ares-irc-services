<?php

declare(strict_types=1);

namespace App\Irc\Application\PublishedEvent;

final readonly class ChannelTopicReceivedEvent
{
    public function __construct(
        public string $channelName,
        public ?string $topic,
        public ?string $setterNick = null,
        public ?string $sourceUid = null,
    ) {}
}
