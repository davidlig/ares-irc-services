<?php

declare(strict_types=1);

namespace App\Infrastructure\IRC\Network\Event;

use App\Domain\IRC\ValueObject\ChannelName;

/**
 * @param string[] $params
 */
final readonly class ChannelListModeReceivedEvent
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
