<?php

declare(strict_types=1);

namespace App\Application\ChanServ\PublishedEvent;

/** Published synchronously inside the channel deletion transaction. */
final readonly class ChannelDropCleanupEvent
{
    public function __construct(public int $channelId) {}
}
