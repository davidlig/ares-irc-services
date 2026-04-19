<?php

declare(strict_types=1);

namespace App\Infrastructure\IRC\Network\Event;

use App\Domain\IRC\ValueObject\ChannelName;

/**
 * @param string[] $modeParams
 */
final readonly class ChannelModeReceivedEvent
{
    /**
     * @param string[] $modeParams
     */
    public function __construct(
        public readonly ChannelName $channelName,
        public readonly string $modeStr,
        public readonly array $modeParams = [],
    ) {
    }
}
