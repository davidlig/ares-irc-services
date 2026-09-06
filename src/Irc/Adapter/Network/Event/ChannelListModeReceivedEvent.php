<?php

declare(strict_types=1);

namespace App\Irc\Adapter\Network\Event;

use App\Irc\Domain\ValueObject\ChannelName;

/**
 * @param string[] $params
 */
final readonly class ChannelListModeReceivedEvent
{
    /**
     * @param string[] $params
     */
    public function __construct(
        public ChannelName $channelName,
        public string $modeChar,
        public array $params,
    ) {}
}
