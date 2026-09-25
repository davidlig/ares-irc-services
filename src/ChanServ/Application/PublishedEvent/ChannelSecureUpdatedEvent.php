<?php

declare(strict_types=1);

namespace App\ChanServ\Application\PublishedEvent;

final readonly class ChannelSecureUpdatedEvent
{
    public function __construct(public string $channelName) {}
}
