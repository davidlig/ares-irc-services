<?php

declare(strict_types=1);

namespace App\Domain\IRC\Event;

use App\Domain\IRC\ValueObject\ChannelName;

/**
 * Raw protocol event: FTOPIC received. Carries channel and topic.
 * Enricher finds channel, updates topic, saves, and dispatches ChannelTopicChangedEvent.
 */
readonly class FtopicReceivedEvent
{
    public function __construct(
        public readonly ChannelName $channelName,
        public readonly ?string $topic,
    ) {
    }
}
