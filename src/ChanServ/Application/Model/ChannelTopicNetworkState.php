<?php

declare(strict_types=1);

namespace App\ChanServ\Application\Model;

final readonly class ChannelTopicNetworkState
{
    public function __construct(
        public string $channelName,
        public ?string $topic,
    ) {}
}
