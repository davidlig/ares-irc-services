<?php

declare(strict_types=1);

namespace App\Domain\IRC\Event;

use App\Domain\IRC\ValueObject\ChannelName;

/**
 * Raw protocol event: FTOPIC/TOPIC received. Carries channel, topic and optional setter nick.
 * Enricher finds channel, updates topic, saves, and dispatches ChannelTopicChangedEvent.
 */
final readonly class FtopicReceivedEvent
{
    public function __construct(
        public readonly ChannelName $channelName,
        public readonly ?string $topic,
        /** Nickname of who set the topic (null if unknown or from services). */
        public readonly ?string $setterNick = null,
    ) {
    }
}
