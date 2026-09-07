<?php

declare(strict_types=1);

namespace App\Irc\Application\PublishedEvent;

final readonly class ChannelSynchronizedEvent
{
    public function __construct(public string $channelName) {}
}
