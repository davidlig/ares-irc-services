<?php

declare(strict_types=1);

namespace App\ChanServ\Application\PublishedEvent;

use App\ChanServ\Application\Port\In\ChanServEvent;

final readonly class ChannelSecureUpdatedEvent implements ChanServEvent
{
    public function __construct(public string $channelName) {}
}
