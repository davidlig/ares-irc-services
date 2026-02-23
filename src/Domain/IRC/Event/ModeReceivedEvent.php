<?php

declare(strict_types=1);

namespace App\Domain\IRC\Event;

use App\Domain\IRC\ValueObject\ChannelName;

/**
 * Raw protocol event: MODE (channel) received (UnrealIRCd). Carries channel, mode string, and params.
 * Enricher finds channel, updates modes/roles/lists, saves, and dispatches ChannelModesChangedEvent.
 *
 * @param string[] $modeParams
 */
readonly class ModeReceivedEvent
{
    /**
     * @param string[] $modeParams
     */
    public function __construct(
        public readonly ChannelName $channelName,
        public readonly string $modeStr,
        public readonly array $modeParams,
    ) {
    }
}
