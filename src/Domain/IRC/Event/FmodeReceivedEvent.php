<?php

declare(strict_types=1);

namespace App\Domain\IRC\Event;

use App\Domain\IRC\ValueObject\ChannelName;

/**
 * Raw protocol event: FMODE received. Carries channel and mode string.
 * Enricher finds channel, updates modes, saves, and dispatches ChannelModesChangedEvent.
 */
readonly class FmodeReceivedEvent
{
    public function __construct(
        public readonly ChannelName $channelName,
        public readonly string $modeStr,
    ) {
    }
}
