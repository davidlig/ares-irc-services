<?php

declare(strict_types=1);

namespace App\Infrastructure\IRC\Network\Event;

use App\Irc\Domain\ValueObject\ChannelName;

/**
 * @param string[] $modeParams
 */
final readonly class ChannelModeReceivedEvent
{
    /**
     * @param string[] $modeParams
     */
    public function __construct(
        public ChannelName $channelName,
        public string $modeStr,
        public array $modeParams = [],
    ) {}
}
