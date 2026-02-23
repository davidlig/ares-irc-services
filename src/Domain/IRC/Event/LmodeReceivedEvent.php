<?php

declare(strict_types=1);

namespace App\Domain\IRC\Event;

use App\Domain\IRC\ValueObject\ChannelName;

/**
 * Raw protocol event: LMODE received. Carries channel, mode char, and params.
 * Enricher finds channel, updates list modes (b/e/I), saves, and dispatches ChannelModesChangedEvent.
 *
 * @param string[] $params
 */
readonly class LmodeReceivedEvent
{
    /**
     * @param string[] $params
     */
    public function __construct(
        public readonly ChannelName $channelName,
        public readonly string $modeChar,
        public readonly array $params,
    ) {
    }
}
