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
        public ChannelName $channelName,
        public string $modeChar,
        public array $params,
    ) {}
}
