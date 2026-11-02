<?php

declare(strict_types=1);

namespace App\ChanServ\Application\PublishedEvent;

use App\ChanServ\Application\Port\In\ChanServEvent;
use DateTimeImmutable;

final readonly class ChannelUnforbiddenEvent implements ChanServEvent
{
    public function __construct(
        public string $channelName,
        public string $channelNameLower,
        public string $performedBy,
        public DateTimeImmutable $occurredAt,
    ) {}
}
