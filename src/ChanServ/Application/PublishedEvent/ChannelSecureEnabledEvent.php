<?php

declare(strict_types=1);

namespace App\ChanServ\Application\PublishedEvent;

use App\ChanServ\Application\Port\In\ChanServEvent;

/**
 * Dispatched when SECURE is turned ON for a channel.
 * Listeners may strip modes from users without access in that channel.
 */
final readonly class ChannelSecureEnabledEvent implements ChanServEvent
{
    public function __construct(
        public string $channelName,
    ) {}
}
